<?php

namespace block_listusers\controllers;

use block_listusers\actions\GradeAction;

class GradeController extends AbstractController
{
    public static function actions(): array
    {
        return [
            'save_grade' => GradeAction::class
        ];
    }
}
