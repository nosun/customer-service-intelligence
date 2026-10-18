<?php

namespace App\Providers;

use App\Models\ProductQuestionDraftRequest;
use App\Observers\ProductQuestionDraftRequestObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProductQuestionDraftRequest::observe(ProductQuestionDraftRequestObserver::class);
    }
}
