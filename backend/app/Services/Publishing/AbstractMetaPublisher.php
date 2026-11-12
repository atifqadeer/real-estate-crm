<?php
namespace App\Services\Publishing;

use App\Models\ContentPostTarget;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaGraphClient;

abstract class AbstractMetaPublisher implements PublisherInterface
{
    public function __construct(protected MetaGraphClient $graph) {}

    protected function token(ContentPostTarget $target): string
    {
        $token = $target->account->token?->access_token;
        if (!$token) {
            throw new PublishingException('No stored token for this account. Reconnect it.', permanent: true, providerCode: 'no_token');
        }
        return $token;
    }

    public function validateAccount(ContentPostTarget $target): void
    {
        $account = $target->account;
        if ($account->status === 'disconnected') {
            throw new PublishingException('Account is disconnected.', permanent: true, providerCode: 'disconnected');
        }
        if (in_array($account->status, ['expired', 'error'], true)) {
            throw new PublishingException("Account connection is {$account->status}. Test or reconnect it first.", permanent: true, providerCode: 'account_' . $account->status);
        }
        $this->token($target);
    }

    /** Required scope check — declined scopes make publishing a permanent failure until reconnect. */
    protected function requireScope(ContentPostTarget $target, string $scope): void
    {
        $declined = $target->account->permissions()->where('permission', $scope)->where('status', 'declined')->exists();
        if ($declined) {
            throw new PublishingException("Permission {$scope} was declined for this account. Reconnect and grant it.", permanent: true, providerCode: 'permission_declined');
        }
    }

    protected function wrap(MetaApiException $e): PublishingException
    {
        return new PublishingException(
            $e->getMessage(),
            permanent: MetaGraphClient::isPermanent($e),
            providerCode: $e->graphCode ? (string) $e->graphCode : null,
        );
    }
}
