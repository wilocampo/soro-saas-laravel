<?php

namespace App\Domain\Inventory\Exceptions;

/** The movement would drive on-hand negative and the policy is `block` (D17). */
class InsufficientStock extends InventoryException {}
