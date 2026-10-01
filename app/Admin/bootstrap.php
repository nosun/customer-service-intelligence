<?php

use Encore\Admin\Form;
use Encore\Admin\Grid;

Grid::init(function (Grid $grid): void {
    $grid->disableExport();
    $grid->disableColumnSelector();
    $grid->disableBatchActions();
});

Form::forget(['map', 'editor']);
