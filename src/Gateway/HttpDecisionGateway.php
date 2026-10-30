<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Data\GatewayAnswer;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;

/**
 * The harness's decide gateway over HTTP. Failures say what went wrong but
 * never carry the response body: it can echo the mail being typed.
 */
final readonly class HttpDecisionGateway implements DecisionGateway
{
    private const QUESTION = 'question';

    public function __construct(private Factory $http) {}

    public function ask(TypeSet $typeSet, string $state, string $refId): GatewayAnswer
    {
        $response = $this->post('/v1/systemone', [
            'model' => (string) config('mailroom.typing.gateway_model'),
            'state' => $state,
            'questions' => [
                self::QUESTION => [
                    'type' => 'choice',
                    'instructions' => $typeSet->question,
                    'criteria' => array_column($typeSet->types, 'description', 'key'),
                ],
            ],
            'decide' => [
                'ref' => $this->ref($refId),
                'tags' => [$typeSet->key],
            ],
        ]);

        $choice = $response->json('answers.'.self::QUESTION.'.choice');

        if (! is_string($choice) || $choice === '') {
            throw new GatewayUnavailable('The gateway answered without a choice.');
        }

        $confidence = $response->json('answers.'.self::QUESTION.'.confidence');
        $decisionId = $response->json('decide.decision_id');

        return new GatewayAnswer(
            $choice,
            is_numeric($confidence) ? (float) $confidence : null,
            is_string($decisionId) ? $decisionId : null,
        );
    }

    public function recordLabel(TypeSet $typeSet, string $refId, string $type): void
    {
        $this->post('/v1/labels', [
            'ref' => $this->ref($refId),
            'question_key' => self::QUESTION,
            'value' => $type,
            'source' => 'human',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws GatewayUnavailable
     */
    private function post(string $path, array $body): Response
    {
        $url = rtrim((string) config('mailroom.typing.gateway_url'), '/').$path;

        try {
            $response = $this->http
                ->timeout((int) config('mailroom.typing.gateway_timeout'))
                ->acceptJson()
                ->asJson()
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable("The gateway could not be reached at {$path}.", $e->getCode(), previous: $e);
        }

        if (! $response->successful()) {
            throw new GatewayUnavailable("The gateway answered {$path} with HTTP {$response->status()}.");
        }

        return $response;
    }

    /**
     * `mail:<address>:<message id>` becomes the gateway's {source, id}.
     *
     * @return array{source: string, id: string}
     */
    private function ref(string $refId): array
    {
        [$source, $id] = explode(':', $refId, 2);

        return ['source' => $source, 'id' => $id];
    }
}
