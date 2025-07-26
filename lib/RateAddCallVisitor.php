<?php
/**
 * Visitor to locate shipping-related AST nodes in parsed PHP files.
 */

namespace KISSShippingDebugger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Unset_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Name;
use PhpParser\Node\Identifier;

class RateAddCallVisitor extends NodeVisitorAbstract {
    private array $addRateNodes    = [];
    private array $filterHookNodes = [];
    private array $feeHookNodes    = [];
    private array $errorAddNodes   = [];
    private array $unsetRateNodes  = [];
    private array $newRateNodes    = [];
    private array $addFeeNodes     = [];

    public function enterNode(Node $node) {
        // 1) $package->add_rate(...)
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add_rate'
        ) {
            $this->addRateNodes[] = $node;
        }

        // 2) add_filter('woocommerce_package_rates', ...)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_filter'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && $node->args[0]->value->value === 'woocommerce_package_rates'
        ) {
            $this->filterHookNodes[] = $node;
        }

        // 3) add_action('woocommerce_cart_calculate_fees', ...)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_action'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && $node->args[0]->value->value === 'woocommerce_cart_calculate_fees'
        ) {
            $this->feeHookNodes[] = $node;
        }

        // 4) $errors->add(...)
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add'
            && $node->var instanceof Variable
            && $node->var->name === 'errors'
        ) {
            $this->errorAddNodes[] = $node;
        }

        // 5) unset($rates[...])
        if ($node instanceof Unset_
            && isset($node->vars[0])
            && $node->vars[0] instanceof ArrayDimFetch
            && $node->vars[0]->var instanceof Variable
            && $node->vars[0]->var->name === 'rates'
        ) {
            $this->unsetRateNodes[] = $node;
        }

        // 6) new WC_Shipping_Rate(...)
        if ($node instanceof New_
            && $node->class instanceof Name
            && $node->class->toString() === 'WC_Shipping_Rate'
        ) {
            $this->newRateNodes[] = $node;
        }

        // 7) $cart->add_fee(...)
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add_fee'
        ) {
            $this->addFeeNodes[] = $node;
        }
    }

    public function getAddRateNodes(): array    { return $this->addRateNodes; }
    public function getFilterHookNodes(): array { return $this->filterHookNodes; }
    public function getFeeHookNodes(): array    { return $this->feeHookNodes; }
    public function getErrorAddNodes(): array   { return $this->errorAddNodes; }
    public function getUnsetRateNodes(): array  { return $this->unsetRateNodes; }
    public function getNewRateNodes(): array    { return $this->newRateNodes; }
    public function getAddFeeNodes(): array     { return $this->addFeeNodes; }
}