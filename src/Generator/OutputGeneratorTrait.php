<?php

namespace Joli\Jane\OpenApi\Generator;

use Joli\Jane\Generator\Context\Context;
use Joli\Jane\Runtime\Reference;
use Joli\Jane\Reference\Resolver;
use Joli\Jane\OpenApi\Model\Schema;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Scalar;

trait OutputGeneratorTrait
{
    /**
     * @return Resolver
     */
    abstract protected function getResolver();

    /**
     * @param         $status
     * @param         $schema
     * @param Context $context
     *
     * @return null|Stmt\If_
     */
    protected function createResponseDenormalizationStatement($status, $schema, Context $context)
    {
        $resolvedSchema = null;
        $array          = false;

        if ($schema instanceof Reference) {
            $resolvedSchema = $this->getResolver()->resolve($schema);
        }

        if ($schema instanceof Schema && $schema->getType() == "array" && $schema->getItems() instanceof Reference) {
            $resolvedSchema = $this->getResolver()->resolve($schema->getItems());
            $array          = true;
        }

        if ($resolvedSchema === null) {
            return [null, null];
        }

        // if the reference is an array, we need to resolve it
        if ($resolvedSchema->getType() === 'array') {
            // figure out the type of items
            $itemType = $resolvedSchema->getItems();
            if ($itemType && method_exists($itemType, 'getReference') && $itemType->getReference()) {
                list($hash, $def, $name) = explode('/', $itemType->getReference());
                if (!empty($name)) {
                    $class = $context->getNamespace() . "\\Model\\" . $name . "[]";
                } else {
                    echo "Error: Unable to resolve reference: " . $itemType->getReference() . "\n";
                    exit(1);
                }
            } else {
                echo "Error: Please use a reference for " . $resolvedSchema->getName() . "\n";
                exit(1);
            }
        } else {
            $class = $context->getObjectClassMap()[spl_object_hash($resolvedSchema)];
            $class = $context->getNamespace() . "\\Model\\" . $class->getName();
            if ($array) {
                $class .= "[]";
            }
        }

        return ["\\" . $class, new Stmt\If_(
            new Expr\BinaryOp\Equal(
                new Scalar\String_($status),
                new Expr\MethodCall(new Expr\Variable('response'), 'getStatusCode')
            ),
            [
                'stmts' => [
                    new Stmt\Return_(new Expr\MethodCall(
                        new Expr\PropertyFetch(new Expr\Variable('this'), 'serializer'),
                        'deserialize',
                        [
                            new Arg(new Expr\Cast\String_(new Expr\MethodCall(new Expr\Variable('response'), 'getBody'))),
                            new Arg(new Scalar\String_($class)),
                            new Arg(new Scalar\String_('json'))
                        ]
                    ))
                ]
            ]
        )];
    }
}
