<?php

declare(strict_types=1);

namespace Specrig\Laravel;

// The static state Laravel's own TestCase flushes between tests, as far as the
// installed version has it: one process runs every case.
final class Flush
{
    private const STATE = [
        ['Illuminate\Console\Application', 'forgetBootstrappers'],
        ['Illuminate\Foundation\Bootstrap\HandleExceptions', 'forgetApp'],
        ['Illuminate\Foundation\Bootstrap\RegisterProviders', 'flushState'],
        ['Illuminate\Foundation\Console\AboutCommand', 'flushState'],
        ['Illuminate\Foundation\Http\FormRequest', 'flushState'],
        ['Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull', 'flushState'],
        ['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'flushState'],
        ['Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance', 'flushState'],
        ['Illuminate\Foundation\Http\Middleware\TrimStrings', 'flushState'],
        ['Illuminate\Foundation\Http\Middleware\ValidateCsrfToken', 'flushState'],
        ['Illuminate\Cookie\Middleware\EncryptCookies', 'flushState'],
        ['Illuminate\Http\Middleware\HandleCors', 'flushState'],
        ['Illuminate\Http\Middleware\TrustHosts', 'flushState'],
        ['Illuminate\Http\Middleware\TrustProxies', 'flushState'],
        ['Illuminate\Http\Resources\Json\JsonResource', 'flushState'],
        ['Illuminate\Http\Resources\JsonApi\JsonApiResource', 'flushState'],
        ['Illuminate\Http\Client\Response', 'flushState'],
        ['Illuminate\Database\Eloquent\Factories\Factory', 'flushState'],
        ['Illuminate\Database\Migrations\Migrator', 'withoutMigrations', []],
        ['Illuminate\Mail\Markdown', 'flushState'],
        ['Illuminate\Queue\Console\WorkCommand', 'flushState'],
        ['Illuminate\Queue\Queue', 'createPayloadUsing', null],
        ['Illuminate\Support\EncodedHtmlString', 'flushState'],
        ['Illuminate\Support\Lottery', 'determineResultsNormally'],
        ['Illuminate\Support\Once', 'flush'],
        ['Illuminate\Support\Sleep', 'fake', false],
        ['Illuminate\Support\Str', 'resetFactoryState'],
        ['Illuminate\Validation\Validator', 'flushState'],
        ['Illuminate\View\Component', 'flushCache'],
        ['Illuminate\View\Component', 'forgetComponentsResolver'],
        ['Illuminate\View\Component', 'forgetFactory'],
        ['Laravel\Prompts\Prompt', 'flushState'],
    ];

    public static function state(): void
    {
        foreach (self::STATE as $call) {
            [$class, $method] = $call;
            if (method_exists($class, $method)) {
                $class::$method(...array_slice($call, 2));
            }
        }
    }
}
