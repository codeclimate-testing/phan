<?php declare(strict_types=1);
namespace Phan\Language;

use \Phan\Debug;
use \Phan\Language\AST\Element;
use \Phan\Language\AST\Visitor\ClassNameKindVisitor;
use \Phan\Language\AST\Visitor\ClassNameValidationVisitor;
use \Phan\Language\Element\Variable;
use \Phan\Log;
use \Phan\Language\UnionType;
use \ast\Node;

/**
 * A set of methods for extracting details from AST nodes.
 */
trait AST {

    /**
     * ast_node_type() is for places where an actual type
     * name appears. This returns that type name. Use node_type()
     * instead to figure out the type of a node
     *
     * @param Context $context
     * @param null|string|Node $node
     *
     * @see \Phan\Deprecated\AST::ast_node_type
     */
    protected static function astUnionTypeFromSimpleNode(
        Context $context,
        $node
    ) : UnionType {
        if($node instanceof \ast\Node) {
            switch($node->kind) {
            case \ast\AST_NAME:
                $result = static::astQualifiedName($context, $node);
                break;
            case \ast\AST_TYPE:
                if($node->flags == \ast\flags\TYPE_CALLABLE) {
                    $result = 'callable';
                } else if($node->flags == \ast\flags\TYPE_ARRAY) {
                    $result = 'array';
                }
                else assert(false, "Unknown type: {$node->flags}");
                break;
            default:
                Log::err(
                    Log::EFATAL,
                    "ast_node_type: unknown node type: "
                    . \ast\get_kind_name($node->kind)
                );
                break;
            }
        } else {
            $result = (string)$node;
        }

        return UnionType::typeFromString($result);
    }

    /**
     * @param Context $context
     * @param null|string|Node $node
     *
     * @return string
     * The class name associated with nodes of various types
     *
     * @see \Phan\Deprecated\Util::find_class_name
     * Formerly `function find_class_name`
     */
    protected static function astClassNameFromNode(
        Context $context,
        $node
    ) : string {
        // Extract the class name
        $class_name = (new Element($node))->acceptKindVisitor(
            new ClassNameKindVisitor($context)
        );

        if (empty($class_name)) {
            return '';
        }

        // Validate that the class name is correct
        if (!(new Element($node))->acceptKindVisitor(
            new ClassNameValidationVisitor($context, $class_name)
        )) {
            return '';
        }

        return $class_name;
    }

    /**
     * Get a list of fully qualified names from a node
     *
     * @return string[]
     *
     * @see \Phan\Deprecated\node_namelist
     * Formerly `function node_namelist`
     */
    protected static function astQualifiedNameList(
        Context $context,
        $node
    ) : array {
        if(!($node instanceof Node)) {
            return [];
        }

        return array_map(function($name_node) use ($context) {
            return self::astQualifiedName($context, $name_node);
        }, $node->children);
    }

    /**
     * Get a fully qualified name form a node
     *
     * @return string
     *
     * @see \Phan\Deprecated\Util::qualified_name
     * From `function qualified_name`
     */
    protected static function astQualifiedName(
        Context $context,
        $node
    ) : string {
        if(!($node instanceof \ast\Node)
            && $node->kind != \ast\AST_NAME
        ) {
            return self::astVarUnionType($context, $node);
        }

        $name = $node->children[0];
        $type = new UnionType([$name]);

        if($node->flags & \ast\flags\NAME_NOT_FQ) {

            // is it a simple native type name?
            if($type->isNativeType()) {
                return (string)$type;
            }

            // Not fully qualified, check if we have an exact
            // namespace alias for it
            if ($context->hasNamespaceMapFor(T_CLASS, (string)$type)) {
                return
                    (string)$context->getNamespaceMapFor(T_CLASS, (string)$type);
            }

            // Check for a namespace-relative alias
            if(($pos = strpos((string)$type, '\\')) !== false) {

                $first_part = substr((string)$type, 0, $pos);

                if ($context->hasNamespaceMapFor(T_CLASS, $first_part)) {
                    $qualified_first_part =
                        (string)$context->getNamespaceMapFor(T_CLASS, $first_part);

                    // Replace that first aliases part and return the full name
                    return $qualified_first_part
                        . '\\'
                        . substr((string)$type, $pos + 1);
                }
            }

            // No aliasing, just prepend the namespace
            return $context->getNamespace() . '\\' . (string)$type;
        } else {
            return $name;
        }
    }

    /**
     * Takes an AST_VAR node and tries to find the variable in
     * the current scope and returns its likely type. For
     * pass-by-ref args, we suppress the not defined error message
     *
     * @param Context $context
     * @param null|string\Node $node
     *
     * @return UnionType
     *
     * @see \Phan\Deprecated\Pass2::var_type
     * From `function var_type`
     */
    protected static function astVarUnionType(
        Context $context,
        $node
    ) : UnionType {

        // Check for $$var or ${...} (whose idea was that anyway?)
        if(($node->children[0] instanceof Node)
            && ($node->children[0]->kind == \ast\AST_VAR
                || $node->children[0]->kind == \ast\AST_BINARY_OP)
        ) {
            return new UnionType(['mixed']);
        }

        if($node->children[0] instanceof Node) {
            return new UnionType();
        }

        $variable_name = $node->children[0];

        // if(empty($scope[$current_scope]['vars'][$node->children[0]])
        if (!$context->getScope()->hasVariableWithName($variable_name)) {
            if(!Variable::isSuperglobalVariableWithName($variable_name))
                Log::err(
                    Log::EVAR,
                    "Variable \${$node->children[0]} is not defined",
                    $context->getFile(),
                    $node->lineno
                );
        } else {
            $variable =
                $context->getScope()->getVariableWithName($variable_name);

            return $variable->getUnionType();

            /*
            if(!empty($scope[$current_scope]['vars'][$node->children[0]]['tainted'])
            ) {
                $tainted_by =
                    $scope[$current_scope]['vars'][$node->children[0]]['tainted_by'];
                $taint = true;
            }
            */
        }

        return new UnionType();
    }

    /**
     * @return string
     * A variable name associated with the given node
     */
    public static function astVariableName($node) : string {
        if(!$node instanceof \ast\Node) {
            return (string)$node;
        }

        $parent = $node;

        while(($node instanceof \ast\Node)
            && ($node->kind != \ast\AST_VAR)
            && ($node->kind != \ast\AST_STATIC)
            && ($node->kind != \ast\AST_MAGIC_CONST)
        ) {
            $parent = $node;
            $node = $node->children[0];
        }

        if(!$node instanceof \ast\Node) {
            return (string)$node;
        }

        if(empty($node->children[0])) {
            return '';
        }

        if($node->children[0] instanceof \ast\Node) {
            return '';
        }

        return (string)$node->children[0];
    }

}
