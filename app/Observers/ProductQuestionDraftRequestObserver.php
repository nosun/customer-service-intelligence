<?php

namespace App\Observers;

use App\Models\ProductQuestionDraftRequest;
use App\Services\Work\ProductQuestionWorkItemManager;

class ProductQuestionDraftRequestObserver
{
    public function saved(ProductQuestionDraftRequest $request): void
    {
        app(ProductQuestionWorkItemManager::class)->sync($request);
    }
}
