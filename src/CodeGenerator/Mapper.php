<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2018 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Management\CodeGenerator;

use Contentful\Core\Api\DateTimeImmutable;
use Contentful\Core\Api\Link;
use Contentful\Management\Mapper\BaseMapper;
use Contentful\Management\Resource\ContentType;
use Contentful\Management\Resource\ContentType\Field\ArrayField;
use Contentful\Management\Resource\ContentType\Field\DateField;
use Contentful\Management\Resource\ContentType\Field\FieldInterface;
use Contentful\Management\Resource\ContentType\Field\LinkField;
use Contentful\Management\SystemProperties;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Mapper class.
 */
class Mapper extends BaseCodeGenerator
{
    /**
     * @var array
     */
    private $uses = [];

    /**
     * Restore the uses array to default values.
     */
    private function setDefaultUses()
    {
        $this->uses = [
            'link' => false,
            'date' => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate(array $params): string
    {
        $contentType = $params['content_type'];
        $namespace = $params['namespace'];

        $this->setDefaultUses();

        $className = $this->convertToStudlyCaps($contentType->getId());

        $class = $this->generateClass($contentType);

        /** @var Stmt[] $statements */
        $statements = $this->generateUses([
            $namespace.'\\'.$className,
            BaseMapper::class,
            SystemProperties::class,
            $this->uses['date'] ? DateTimeImmutable::class : null,
            $this->uses['link'] ? Link::class : null,
        ]);

        $statements[] = $class;

        return $this->render(
            new Node\Stmt\Namespace_(new Node\Name($namespace.'\\Mapper'), $statements)
        );
    }

    /**
     * @param ContentType $contentType
     *
     * @return Class_
     */
    private function generateClass(ContentType $contentType): Class_
    {
        $className = $this->convertToStudlyCaps($contentType->getId());

        return new Node\Stmt\Class_(
            $className.'Mapper',
            [
                'extends' => new Node\Name('BaseMapper'),
                'stmts' => [
                    $this->generateMapMethod($contentType),
                    $this->generateFormatMethod($contentType),
                ],
            ],
            $this->generateCommentAttributes(\sprintf(
                "\n".'/**
                 * %sMapper class.
                 *
                 * This class was autogenerated.
                 */',
                $className
            ))
        );
    }

    /**
     * Generates the following code.
     *
     * ```
     * public function map($resource, array $data): ClassName
     * {
     *     return $this->hydrate($resource ?? ClassName::class, [
     *         'sys' => new SystemProperties($data['sys']),
     *         // Delegate the formatting of all fields
     *         'fields' => $this->formatFields($data),
     *     ]);
     * }
     * ```
     *
     * @param ContentType $contentType
     *
     * @return ClassMethod
     */
    private function generateMapMethod(ContentType $contentType): ClassMethod
    {
        $className = $this->convertToStudlyCaps($contentType->getId());

        return new ClassMethod(
            'map',
            [
                'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC,
                'params' => [
                    new Node\Param('resource'),
                    new Node\Param('data', null, 'array'),
                ],
                'returnType' => new Node\Name($className),
                'stmts' => [
                    new Node\Stmt\Return_(
                        new Node\Expr\MethodCall(
                            new Node\Expr\Variable('this'),
                            'hydrate',
                            $this->generateMapperMethodHydrateArgs($className)
                        )
                    ),
                ],
            ],
            $this->generateCommentAttributes('/**
                * {@inheritdoc}
                */')
        );
    }

    /**
     * Generates the following code.
     *
     * ```
     * $resource ?? ClassName::class, [
     *     'sys' => new SystemProperties($data['sys']),
     *     // Delegate the formatting of all fields
     *     'fields' => $this->formatFields($data),
     * ]
     * ```
     *
     * @param string $className
     *
     * @return Node\Arg[]
     */
    private function generateMapperMethodHydrateArgs(string $className): array
    {
        return [
            new Node\Arg(
                new Node\Expr\BinaryOp\Coalesce(
                    new Node\Expr\Variable('resource'),
                    new Node\Expr\ClassConstFetch(new Node\Name($className), 'class')
                )
            ),
            new Node\Arg(
                new Node\Expr\Array_($this->generateMapperMethodHydrateArgsArray())
            ),
        ];
    }

    /**
     * @return Node\Expr\ArrayItem[]
     */
    private function generateMapperMethodHydrateArgsArray(): array
    {
        return [
            new Node\Expr\ArrayItem(
                new Node\Expr\New_(new Node\Name('SystemProperties'), [
                    new Node\Arg(new Node\Expr\ArrayDimFetch(
                        new Node\Expr\Variable('data'),
                        new Node\Scalar\String_('sys')
                    )),
                ]),
                new Node\Scalar\String_('sys')
            ),
            new Node\Expr\ArrayItem(
                new Node\Expr\MethodCall(
                    new Node\Expr\Variable('this'),
                    'formatFields',
                    [
                        new Node\Arg(new Node\Expr\BinaryOp\Coalesce(
                            new Node\Expr\ArrayDimFetch(
                                new Node\Expr\Variable('data'),
                                new Node\Scalar\String_('fields')
                            ),
                            new Node\Expr\Array_([])
                        )),
                    ]
                ),
                new Node\Scalar\String_('fields'),
                false,
                // This is actually a hack for forcing arrays
                // to be displayed on multiple lines. Oh, well...
                $this->generateCommentAttributes('// Delegate the formatting of all fields')
            ),
        ];
    }

    /**
     * Generates the following code.
     *
     * ```
     * private function formatFields(array $data): array
     * {
     *     $fields = [];
     *     // ...
     *
     *     return $fields;
     * }
     * ```
     *
     * @param ContentType $contentType
     *
     * @return ClassMethod
     */
    private function generateFormatMethod(ContentType $contentType): ClassMethod
    {
        $method = new ClassMethod(
            'formatFields',
            [
                'flags' => Node\Stmt\Class_::MODIFIER_PRIVATE,
                'params' => [
                    new Node\Param('data', null, 'array'),
                ],
                'returnType' => new Node\Name('array'),
                'stmts' => [
                    new Node\Expr\Assign(
                        new Node\Expr\Variable('fields'),
                        new Node\Expr\Array_([])
                    ),
                ],
            ],
            $this->generateCommentAttributes("\n".'/**
                * @param array $data
                *
                * @return array
                */')
        );

        foreach ($contentType->getFields() as $field) {
            $method->stmts = \array_merge($method->stmts, $this->generateFieldAssignment($field));
        }

        $method->stmts[] = new Node\Stmt\Return_(
            new Node\Expr\Variable('fields'),
            $this->generateCommentAttributes('')
        );

        return $method;
    }

    /**
     * @param FieldInterface $field
     *
     * @return Node[]
     */
    private function generateFieldAssignment(FieldInterface $field): array
    {
        if ($field instanceof LinkField) {
            return $this->generateLinkFieldAssignment($field);
        }

        if ($field instanceof ArrayField) {
            if ('Link' === $field->getItemsType()) {
                return $this->generateArrayLinkFieldAssignment($field);
            }

            return $this->generateDefaultFieldAssignment($field);
        }

        if ($field instanceof DateField) {
            return $this->generateDateFieldAssignment($field);
        }

        return $this->generateDefaultFieldAssignment($field);
    }

    /**
     * Generates the following code.
     *
     * ```
     * $fields['name'] = [];
     * foreach ($data['name'] as $locale => $value) {
     *     $fields['name'][$locale] = \array_map(function (array $link): Link {
     *         return new Link($link['sys']['id'], $link['sys']['linkType']);
     *     }, $value);
     * }
     * ```
     *
     * @param ArrayField $field
     *
     * @return Node[]
     */
    private function generateArrayLinkFieldAssignment(ArrayField $field): array
    {
        $this->uses['link'] = true;

        return $this->generateForeachAssignment($field, new Node\Expr\FuncCall(
            new Node\Name('\\array_map'),
            [
                new Node\Arg(
                    new Node\Expr\Closure([
                        'params' => [new Node\Param('link', null, 'array')],
                        'returnType' => new Node\Name('Link'),
                        'stmts' => [
                            new Node\Stmt\Return_($this->generateNewLinkStatement('link')),
                        ],
                    ])
                ),
                new Node\Arg(new Node\Expr\Variable('value')),
            ]
        ));
    }

    /**
     * @param LinkField $field
     *
     * @return Node[]
     */
    private function generateLinkFieldAssignment(LinkField $field): array
    {
        $this->uses['link'] = true;

        return $this->generateForeachAssignment(
            $field,
            $this->generateNewLinkStatement('value')
        );
    }

    /**
     * Generates the following code.
     *
     * ```
     * $fields['name'] = [];
     * foreach ($data['name'] as $locale => $value) {
     *     $fields['name'][$locale] = new DateTimeImmutable($value);
     * }
     * ```
     *
     * @param DateField $field
     *
     * @return Node[]
     */
    private function generateDateFieldAssignment(DateField $field): array
    {
        $this->uses['date'] = true;

        return $this->generateForeachAssignment(
            $field,
            new Node\Expr\New_(
                new Node\Name('DateTimeImmutable'),
                [
                    new Node\Arg(new Node\Expr\Variable('value')),
                ]
            )
        );
    }

    /**
     * Generates the following code.
     *
     * ```
     * $fields['name'] = $data['name'] ?? null
     * ```
     *
     * @param FieldInterface $field
     *
     * @return Node\Expr[]
     */
    private function generateDefaultFieldAssignment(FieldInterface $field): array
    {
        return [
            new Node\Expr\Assign(
                new Node\Expr\ArrayDimFetch(
                    new Node\Expr\Variable('fields'),
                    new Node\Scalar\String_($field->getId())
                ),
                new Node\Expr\BinaryOp\Coalesce(
                    new Node\Expr\ArrayDimFetch(
                        new Node\Expr\Variable('data'),
                        new Node\Scalar\String_($field->getId())
                    ),
                    new Node\Expr\ConstFetch(new Node\Name('null'))
                )
            ),
        ];
    }

    /**
     * Generates the following code.
     *
     * ```
     * new Link($varName['sys']['id'], $varName['sys']['linkType'])
     * ```
     *
     * @param string $varName
     *
     * @return Node\Expr\New_
     */
    private function generateNewLinkStatement(string $varName): Node\Expr\New_
    {
        return new Node\Expr\New_(
            new Node\Name('Link'),
            [
                new Node\Arg(
                    new Node\Expr\ArrayDimFetch(
                        new Node\Expr\ArrayDimFetch(
                            new Node\Expr\Variable($varName),
                            new Node\Scalar\String_('sys')
                        ),
                        new Node\Scalar\String_('id')
                    )
                ),
                new Node\Arg(
                    new Node\Expr\ArrayDimFetch(
                        new Node\Expr\ArrayDimFetch(
                            new Node\Expr\Variable($varName),
                            new Node\Scalar\String_('sys')
                        ),
                        new Node\Scalar\String_('linkType')
                    )
                ),
            ]
        );
    }

    /**
     * Generates the following code.
     *
     * ```
     * $fields['name'] = [];
     * foreach ($data['name'] as $locale => $value) {
     *     $fields['name'][$locale] = {{ $expr }};
     * }
     * ```
     *
     * @param FieldInterface $field
     * @param Node\Expr      $expr
     *
     * @return Node[]
     */
    private function generateForeachAssignment(FieldInterface $field, Node\Expr $expr): array
    {
        return [
            new Node\Expr\Assign(
                new Node\Expr\ArrayDimFetch(new Node\Expr\Variable('fields'), new Node\Scalar\String_($field->getId())),
                new Node\Expr\Array_([])
            ),
            new Node\Stmt\Foreach_(
                new Node\Expr\BinaryOp\Coalesce(
                    new Node\Expr\ArrayDimFetch(new Node\Expr\Variable('data'), new Node\Scalar\String_($field->getId())),
                    new Node\Expr\Array_([])
                ),
                new Node\Expr\Variable('value'),
                [
                    'keyVar' => new Node\Expr\Variable('locale'),
                    'stmts' => [
                        new Node\Expr\Assign(
                            new Node\Expr\ArrayDimFetch(
                                new Node\Expr\ArrayDimFetch(new Node\Expr\Variable('fields'), new Node\Scalar\String_($field->getId())),
                                new Node\Expr\Variable('locale')
                            ),
                            $expr
                        ),
                    ],
                ]
            ),
        ];
    }
}
