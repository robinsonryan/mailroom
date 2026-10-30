<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\Gateway\HttpDecisionGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;

beforeEach(function (): void {
    config([
        'mailroom.typing.gateway_url' => 'http://gateway.test:8020/',
        'mailroom.typing.gateway_model' => 'jev',
    ]);
    $this->set = InvoiceSet::register();
    $this->gateway = app(DecisionGateway::class);
});

it('is the bound decision gateway', function (): void {
    expect($this->gateway)->toBeInstanceOf(HttpDecisionGateway::class);
});

it('asks systemone the type set question as one choice over the type keys', function (): void {
    Http::fake(['gateway.test:8020/v1/systemone' => Http::response([
        'answers' => ['question' => ['choice' => 'bill_ready', 'confidence' => 0.87]],
        'decide' => ['decision_id' => 'dec-42'],
    ])]);

    $answer = $this->gateway->ask($this->set, "From: a\nSubject: b", 'mail:info@example.com:m1');

    expect($answer->type)->toBe('bill_ready')
        ->and($answer->confidence)->toBe(0.87)
        ->and($answer->decisionId)->toBe('dec-42');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://gateway.test:8020/v1/systemone'
        && $request->data() === [
            'model' => 'jev',
            'state' => "From: a\nSubject: b",
            'questions' => [
                'question' => [
                    'type' => 'choice',
                    'instructions' => 'What is this email about money?',
                    'criteria' => [
                        'invoice' => 'an invoice',
                        'bill_ready' => 'a bill is ready to fetch',
                        'other' => 'anything else',
                    ],
                ],
            ],
            'decide' => [
                'ref' => ['source' => 'mail', 'id' => 'info@example.com:m1'],
                'tags' => ['invoices'],
            ],
        ]);
});

it('passes a missing confidence and decision id through as null', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['question' => ['choice' => 'other']]])]);

    $answer = $this->gateway->ask($this->set, 'state', 'mail:info@example.com:m1');

    expect($answer->type)->toBe('other')
        ->and($answer->confidence)->toBeNull()
        ->and($answer->decisionId)->toBeNull();
});

it('turns an error status into GatewayUnavailable', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 503)]);

    $this->gateway->ask($this->set, 'state', 'mail:info@example.com:m1');
})->throws(GatewayUnavailable::class);

it('turns a timeout into GatewayUnavailable', function (): void {
    Http::fake(fn () => throw new ConnectionException('Operation timed out'));

    $this->gateway->ask($this->set, 'state', 'mail:info@example.com:m1');
})->throws(GatewayUnavailable::class);

it('turns an answer without a choice into GatewayUnavailable', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['question' => ['confidence' => 0.9]]])]);

    $this->gateway->ask($this->set, 'state', 'mail:info@example.com:m1');
})->throws(GatewayUnavailable::class);

it('does not leak the message state into the failure', function (): void {
    Http::fake(['*' => Http::response('state echoed: a private body', 500)]);

    try {
        $this->gateway->ask($this->set, 'a private body', 'mail:info@example.com:m1');
        $this->fail('Expected GatewayUnavailable.');
    } catch (GatewayUnavailable $e) {
        expect($e->getMessage())->not->toContain('private body');
    }
});

it('records a person\'s answer as a human label on the same ref', function (): void {
    Http::fake(['gateway.test:8020/v1/labels' => Http::response(['ok' => true])]);

    $this->gateway->recordLabel($this->set, 'mail:info@example.com:m1', 'invoice');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://gateway.test:8020/v1/labels'
        && $request->data() === [
            'ref' => ['source' => 'mail', 'id' => 'info@example.com:m1'],
            'question_key' => 'question',
            'value' => 'invoice',
            'source' => 'human',
        ]);
});

it('turns a labels failure into GatewayUnavailable', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    $this->gateway->recordLabel($this->set, 'mail:info@example.com:m1', 'invoice');
})->throws(GatewayUnavailable::class);
