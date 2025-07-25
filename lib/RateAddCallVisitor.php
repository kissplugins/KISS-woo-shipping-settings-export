<?php
// File: lib/RateAddCallVisitor.php

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * A Node Visitor that specifically looks for method calls named 'add_rate'.
 */
class RateAddCallVisitor extends NodeVisitorAbstract {
    private $addRateNodes = [];

    /**
     * This method is called for every node in the AST.
     * We check if the node is a method call with the name 'add_rate'.
     */
    public function enterNode( Node $node ) {
        // We are only interested in nodes that are an instance of a method call.
        if ( $node instanceof Node\Expr\MethodCall ) {
            // Check if the name of the method being called is 'add_rate'.
            if ( $node->name instanceof Node\Identifier && $node->name->name === 'add_rate' ) {
                $this->addRateNodes[] = $node;
            }
        }
    }

    /**
     * Returns the array of nodes that were found to be 'add_rate' calls.
     *
     * @return array
     */
    public function getAddRateNodes(): array {
        return $this->addRateNodes;
    }
}