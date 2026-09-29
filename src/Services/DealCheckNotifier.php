<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BitrixApiException;
use Psr\Log\LoggerInterface;

/**
 * Сбой уведомления не должен ломать проверку: статус в сделку уже записан.
 */
final class DealCheckNotifier
{
    /**
     * @param  array<int, int>  $extraUserIds
     */
    public function __construct(
        private readonly Bitrix24Service $bitrix,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
        private readonly bool $timelineComment = true,
        private readonly bool $notifyAssigned = true,
        private readonly array $extraUserIds = [],
        private readonly bool $emoji = true,
    ) {}

    /**
     * @param  array<string, mixed>  $deal
     * @param  array<int, string>  $reasons
     */
    public function notifyProblem(int $dealId, array $deal, string $headline, array $reasons): void
    {
        if (! $this->enabled) {
            return;
        }

        $title = $this->emoji ? '❌ '.$headline : $headline;

        if ($this->timelineComment) {
            $this->attempt('timeline', $dealId, fn () => $this->bitrix->addDealTimelineComment(
                $dealId,
                implode("\n", array_merge([$title], $reasons)),
            ));
        }

        $message = implode("\n", array_merge([$title.' '.$this->dealLink($dealId, $deal)], $reasons));

        foreach ($this->recipients($deal) as $userId) {
            $this->attempt('im_notify', $dealId, fn () => $this->bitrix->notifyUser($userId, $message));
        }
    }

    /**
     * @param  array<string, mixed>  $deal
     * @return array<int, int>
     */
    private function recipients(array $deal): array
    {
        $ids = $this->extraUserIds;

        if ($this->notifyAssigned) {
            $assigned = $deal['ASSIGNED_BY_ID'] ?? null;

            if (is_numeric($assigned) && (int) $assigned > 0) {
                $ids[] = (int) $assigned;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param  array<string, mixed>  $deal
     */
    private function dealLink(int $dealId, array $deal): string
    {
        $title = trim(is_scalar($deal['TITLE'] ?? null) ? (string) $deal['TITLE'] : '');
        $label = 'Сделка #'.$dealId.($title !== '' ? ' '.$title : '');
        $url = $this->bitrix->dealUrl($dealId);

        return $url === null ? $label : '[URL='.$url.']'.$label.'[/URL]';
    }

    private function attempt(string $channel, int $dealId, callable $send): void
    {
        try {
            $send();
        } catch (BitrixApiException $exception) {
            $this->logger->warning('Bitrix product check notification failed', [
                'deal_id' => $dealId,
                'channel' => $channel,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
