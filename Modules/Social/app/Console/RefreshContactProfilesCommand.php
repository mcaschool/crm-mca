<?php

declare(strict_types=1);

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Services\ContactProfileUpdater;
use Throwable;

/**
 * Recuperación controlada de perfiles incompletos (Messenger/Instagram): completa el nombre y/o
 * la foto de conversaciones antiguas sin esperar a que la persona vuelva a escribir.
 *
 *  - Independiente de la recepción de mensajes (programado cada hora; también manual).
 *  - Limitado: como mucho --limit consultas por ejecución, las más antiguas sin revisar primero.
 *  - Idempotente: respeta la espera entre consultas de ContactProfileUpdater (salvo --force) y
 *    nunca sustituye datos válidos por null.
 *  - Por institución: cada conversación se procesa en el contexto de SU institución y con el
 *    token de SU canal; un canal inactivo se omite.
 */
class RefreshContactProfilesCommand extends Command
{
    protected $signature = 'social:refresh-contact-profiles
        {--limit=25 : Máximo de consultas a Meta en esta ejecución}
        {--force : Consultar aunque no haya pasado la espera entre intentos}';

    protected $description = 'Completa el nombre y la foto de contactos de Messenger e Instagram que no los tienen.';

    public function handle(CurrentInstitution $context, ContactProfileUpdater $profiles): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $force = (bool) $this->option('force');

        /** @var array<int, array{id:int, institution_id:int}> $candidates */
        $candidates = $context->runGlobally(fn () => SocialConversation::query()
            ->whereIn('provider', ContactProfileUpdater::PROVIDERS)
            ->whereNotNull('contact_external_id')->where('contact_external_id', '!=', '')
            ->where(fn ($q) => $q->whereNull('contact_name')->orWhere('contact_name', '')
                ->orWhereNull('contact_avatar_url')->orWhere('contact_avatar_url', ''))
            ->orderByRaw('profile_checked_at IS NOT NULL')->orderBy('profile_checked_at')->orderBy('id')
            ->limit($limit * 4)
            ->get(['id', 'institution_id'])
            ->map(fn (SocialConversation $c) => ['id' => (int) $c->id, 'institution_id' => (int) $c->institution_id])
            ->all());

        $queried = 0;
        $completed = 0;
        foreach ($candidates as $row) {
            if ($queried >= $limit) {
                break;
            }
            try {
                [$asked, $complete] = $context->runFor($row['institution_id'], function () use ($row, $profiles, $force): array {
                    $conversation = SocialConversation::query()->with('channel')->find($row['id']);
                    $channel = $conversation?->channel;
                    if ($conversation === null || $channel === null || ! $channel->is_active) {
                        return [false, false];
                    }
                    $asked = $profiles->refresh($conversation, $channel, $force);

                    return [$asked, $asked && $conversation->profile_status === 'complete'];
                });
            } catch (Throwable $e) {
                $this->warn('Conversación #'.$row['id'].': '.class_basename($e));

                continue;
            }
            $queried += $asked ? 1 : 0;
            $completed += $complete ? 1 : 0;
        }

        $this->info("Perfiles consultados: {$queried}. Completos: {$completed}.");

        return self::SUCCESS;
    }
}
