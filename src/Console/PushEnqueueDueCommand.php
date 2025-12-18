<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Shared\AppOptions;
use App\Profile\ProfileRepository;
use App\Telegram\KeyboardFactory;
use App\User\UserRepository;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'push:enqueue-due',
    description: 'Scan users due for a push and enqueue prepared Telegram messages (respects time window, per-day cap, and last push time)'
)]
final class PushEnqueueDueCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RabbitMqService $mq,
        private readonly CacheInterface $cache,
        private readonly Localizer $t,
        private readonly KeyboardFactory $kb,
        private readonly AppOptions $opts,
        private readonly ProfileRepository $profiles,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Time window: work only between 10:00 and 06:00 UTC (spanning midnight)
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$this->withinWindow($now, 10, 6)) {
            $output->writeln('<comment>Outside working window (UTC). Skipping.</comment>');
            return ExitCode::OK;
        }

        // Simple cache lock to prevent concurrent runs
        $lockKey = 'push_enqueue_lock';
        if ($this->cache->get($lockKey)) {
            $output->writeln('<comment>Another instance is running. Skipping.</comment>');
            return ExitCode::OK;
        }
        $this->cache->set($lockKey, 1, 55); // TTL ~1 minute

        try {
            $this->mq->ensureTopology();

            $batchSize = 1000;
            $maxBatches = 50; // safety to avoid endless loop
            $enqueued = 0;
            for ($i = 0; $i < $maxBatches; $i++) {
                $due = $this->users->findDueUsers($now, $batchSize);
                if (empty($due)) {
                    break;
                }
                foreach ($due as $row) {
                    $userId = (int)$row["user_id"];
                    $lang = $this->t->normalize((string)$row['language']);

                    // Atomically mark: increment daily counter and set last_push; also re-check hour gap
                    if (!$this->users->tryMarkPushEnqueued($userId, $now)) {
                        continue; // cap reached or not enough time passed
                    }

                    // determine preference
                    $pref = $this->users->getPreference($userId);
                    if ($pref !== 'woman' && $pref !== 'man') {
                        // Ask for preference if not set
                        $text = $this->t->t('find_whom.text', $lang);
                        $markup = $this->kb->findWhom($lang);
                        $payload = [
                            'method' => 'sendMessage',
                            'args' => [
                                'chat_id' => $userId,
                                'text' => $text,
                                'reply_markup' => $markup,
                            ],
                        ];
                        $this->mq->publishPush($payload);
                        $enqueued++;
                        continue;
                    }

                    // pick a random profile unseen by user
                    $profile = $this->profiles->getRandomUnseenByGender($userId, $pref);
                    if ($profile === null) {
                        // reset shown and try again once
                        $this->profiles->clearShownForUser($userId);
                        $profile = $this->profiles->getRandomUnseenByGender($userId, $pref);
                    }
                    if ($profile === null) {
                        continue; // nothing to show
                    }

                    $photoUrl = $this->buildPublicPhotoUrl($profile['gender'], $profile['file']);
                    $markup = $this->kb->profileCard($lang, (int)$profile['id'], $userId);
                    $payload = [
                        'method' => 'sendPhoto',
                        'args' => [
                            'chat_id' => $userId,
                            'photo' => $photoUrl,
                            'caption' => '',
                            'reply_markup' => $markup,
                        ],
                    ];

                    $this->mq->publishPush($payload);
                    // mark as shown for the user
                    $this->profiles->markShown($userId, (int)$profile['id']);
                    $enqueued++;
                }
            }

            $output->writeln(sprintf('<info>Enqueued %d push messages.</info>', $enqueued));
            return ExitCode::OK;
        } finally {
            $this->cache->delete($lockKey);
        }
    }

    private function withinWindow(DateTimeImmutable $now, int $startHour, int $endHour): bool
    {
        $h = (int)$now->format('G'); // 0..23
        if ($startHour === $endHour) {
            return true; // full-day
        }
        if ($startHour < $endHour) {
            return $h >= $startHour && $h < $endHour;
        }
        // window spans midnight: e.g. 10..6 => [10..23] U [0..5]
        return $h >= $startHour || $h < $endHour;
    }

    private function buildPublicPhotoUrl(string $gender, string $file): string
    {
        $base = rtrim($this->opts->publicBaseUrl, '/');
        $folder = $gender === 'man' ? 'men' : 'women';
        $encoded = rawurlencode($file);
        return $base . '/storage/profiles/' . $folder . '/' . $encoded;
    }
}
