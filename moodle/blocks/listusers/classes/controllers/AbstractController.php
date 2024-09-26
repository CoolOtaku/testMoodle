<?php

namespace block_listusers\controllers;

use block_listusers\actions\AbstractAction;

/**
 * Example:
 * class Controller extends AbstractController{}
 * Controller::getInstance($action)->execute();
 */
abstract class AbstractController
{
    /**
     * @return array [
     *      'exampleActionName1' => \namespace\actionClass1,
     *      'exampleActionName2' => \namespace\actionClass2,
     *  ];
     */
    abstract public static function actions(): array;

    /** @var AbstractAction */
    protected AbstractAction $action;

    /**
     * @throws \Exception
     */
    public static function getInstance(string $action): AbstractController
    {
        return new static($action);
    }

    /**
     * @throws \Exception
     */
    public function __construct(string $action)
    {
        $actions = static::actions();
        if (!isset($actions[$action])) {
            throw new \RuntimeException('Error: incorrect action.');
        }

        /** @var AbstractAction $actionClass */
        $actionClass = $actions[$action];

        $this->action = $actionClass::getInstance();
    }

    /**
     * @throws \Exception
     */
    public function execute()
    {
        $this->validateAction();

        return $this->action->execute();
    }

    /**
     * @throws \Exception
     */
    protected function validateAction(): void
    {
        if (!$this->action) {
            throw new \Exception('Action not set.');
        }

        if (!($this->action instanceof AbstractAction)) {
            throw new \Exception('Action does not exist.');
        }
    }
}
