<?php

namespace App\Providers;

use App\Domain\Audit\Observers\ConfigurationAuditObserver;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Platform\Services\AgentPackageInspector;
use App\Domain\Platform\Services\NativeAgentPackageInspector;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Observers\PrinterBindingConfigObserver;
use App\Domain\Sources\Observers\SourceConnectionConfigObserver;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(AgentPackageInspector::class, NativeAgentPackageInspector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        SourceConnection::observe(ConfigurationAuditObserver::class);
        SourceConnection::observe(SourceConnectionConfigObserver::class);
        PrinterBinding::observe(ConfigurationAuditObserver::class);
        PrinterBinding::observe(PrinterBindingConfigObserver::class);
        Site::observe(ConfigurationAuditObserver::class);
        Agent::observe(ConfigurationAuditObserver::class);
        Printer::observe(ConfigurationAuditObserver::class);

        RateLimiter::for('agent-enroll', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('agent-api', function (Request $request): Limit {
            $agentId = $request->attributes->get('agent')?->getKey();

            return Limit::perMinute(60)->by($agentId ?? $request->ip());
        });
    }
}
