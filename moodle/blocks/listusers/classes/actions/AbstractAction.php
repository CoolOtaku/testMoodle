<?php

namespace block_listusers\actions;

abstract class AbstractAction
{
    abstract public function execute();

    public static function getInstance(): static
    {
        return new static();
    }
}
