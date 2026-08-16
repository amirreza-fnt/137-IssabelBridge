<?php
declare(strict_types=1);

require_once __DIR__ . '/HttpClient.php';

/**
 * Creates a PhoneCall request on 137-request and returns trackingCode.
 */
final class RequestClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiKeyHeader = 'X-Api-Key'
    ) {}

    /**
     * @param list<string> $fileIds
     * @return array{requestId:string, trackingCode:string}
     */
    public function createPhoneCallRequest(
        ?string $callerPhone,
        string $outcome,          // NO_ANSWER | ANSWERED
        array $fileIds,
        ?string $operatorExt = null,
        ?string $nationalCode = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $extraDescription = null
    ): array {
        $parts = [
            'outcome=' . $outcome,
            $operatorExt ? ('operatorExt=' . $operatorExt) : null,
            $extraDescription ? trim($extraDescription) : null,
            'source=issabel-bridge',
        ];
        $description = implode(' | ', array_values(array_filter($parts)));

        $citizen = [];
        if ($callerPhone !== null && $callerPhone !== '') {
            $citizen['phoneNumber'] = $callerPhone;
        }
        if ($nationalCode) {
            $citizen['nationalCode'] = $nationalCode;
        }
        if ($firstName) {
            $citizen['firstName'] = $firstName;
        }
        if ($lastName) {
            $citizen['lastName'] = $lastName;
        }

        $body = [
            'channel'     => 'PhoneCall',
            'description' => $description,
            'fileIds'     => array_values($fileIds),
        ];
        if ($citizen !== []) {
            $body['citizen'] = $citizen;
        }

        $res = $this->http->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/api/v1/requests',
            $body,
            [$this->apiKeyHeader . ': ' . $this->apiKey]
        );

        if ($res['status'] !== 201 || empty($res['json']['trackingCode'])) {
            throw new RuntimeException(
                'create request failed HTTP ' . $res['status'] . ': ' . $res['body']
            );
        }

        return [
            'requestId'    => (string)$res['json']['requestId'],
            'trackingCode' => (string)$res['json']['trackingCode'],
        ];
    }
}
