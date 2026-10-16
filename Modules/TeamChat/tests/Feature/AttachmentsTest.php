<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatAttachments;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\ChatQueries;
use Modules\TeamChat\Services\SpaceCommands;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';

beforeEach(function (): void {
    Storage::fake('teamchat_private');
    $this->tenant = Institution::factory()->create();
    $this->users = User::factory()->count(3)->create(['institution_id' => $this->tenant->id]);
    [$this->a,$this->b,$this->c] = $this->users->map(fn ($u) => realtimeActor($u))->all();
    $this->chat = app(ChatCommands::class);
    $this->files = app(ChatAttachments::class);
    $this->direct = $this->chat->direct($this->a, $this->b->userId);
});
function prepareAttachment(ActorContext $a, string $conversation, UploadedFile $file, string $kind = 'document', string $command = 'upload'): array
{
    return app(ChatAttachments::class)->prepare($a, $conversation, $command, $file->getClientOriginalName(), $kind, $file->getClientMimeType(), $file->getSize(), hash_file('sha256', $file->getPathname()));
}
function readyAttachment(ActorContext $a, string $conversation, UploadedFile $file, string $kind = 'document', string $command = 'upload'): array
{
    $row = prepareAttachment($a, $conversation, $file, $kind, $command);

    return app(ChatAttachments::class)->upload($a, $row['id'], $file);
}
it('sends private attachments through the existing idempotent message receipts and outbox engine', function () {
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Institutional notes');
    $upload = readyAttachment($this->a, $this->direct, $file);
    expect($upload['state'])->toBe('ready')->and($upload['scan_status'])->toBe('not_scanned');
    expect(fn () => $this->files->status($this->b, $upload['id']))->toThrow(ContractException::class);
    $id = $this->chat->send($this->a, $this->direct, 'with-file', '', [$upload['id']]);
    expect($this->chat->send($this->a, $this->direct, 'with-file', '', [$upload['id']]))->toBe($id);
    $messages = app(ChatQueries::class)->messages($this->b, $this->direct, new PageRequest)['messages'];
    expect($messages[0]['attachments'][0]['id'])->toBe($upload['id'])->and($messages[0]['sequence'])->toBe(1);
    $this->chat->acknowledge($this->b, $this->direct, [$id], true);
    expect(app(ChatQueries::class)->messages($this->a, $this->direct, new PageRequest)['messages'][0]['status'])->toBe('read');
    expect(DB::table('collab_notification_intents')->count())->toBe(1);
    $events = DB::table('collab_events')->pluck('envelope')->implode('');
    expect($events)->not->toContain('Institutional notes')->not->toContain('notes.txt')->not->toContain('content_url')->not->toContain('source_hash');
    expect(fn () => $this->chat->send($this->a, $this->direct, 'another', '', [$upload['id']]))->toThrow(ContractException::class);
    expect(DB::table('collab_chat_messages')->count())->toBe(1);
});
it('supports repeat preparation and upload while rejecting changed or incomplete bytes', function () {
    $file = UploadedFile::fake()->createWithContent('report.txt', 'Original bytes');
    $u = prepareAttachment($this->a, $this->direct, $file);
    expect(prepareAttachment($this->a, $this->direct, $file)['id'])->toBe($u['id']);
    expect(fn () => $this->chat->send($this->a, $this->direct, 'premature', '', [$u['id']]))->toThrow(ContractException::class);
    expect(fn () => $this->files->upload($this->a, $u['id'], UploadedFile::fake()->createWithContent('report.txt', 'short')))->toThrow(ContractException::class);
    expect($this->files->status($this->a, $u['id'])['state'])->toBe('prepared');
    $this->files->upload($this->a, $u['id'], $file);
    $this->files->upload($this->a, $u['id'], $file);
    expect(DB::table('collab_chat_uploads')->count())->toBe(1);
    expect(fn () => prepareAttachment($this->a, $this->direct, UploadedFile::fake()->createWithContent('report.txt', 'Different content')))->toThrow(ContractException::class, 'idempotency_conflict');
});
it('rejects executable MIME mismatches unsafe names and oversized files', function () {
    foreach (['a.exe', 'a.svg', '../a.txt', 'a.php.txt'] as $name) {
        $file = UploadedFile::fake()->createWithContent($name, '<?php evil();');
        try {
            $u = prepareAttachment($this->a, $this->direct, $file, 'document', hash('sha256', $name));
            $this->files->upload($this->a, $u['id'], $file);
            $accepted = true;
        } catch (ContractException) {
            $accepted = false;
        }
        expect($accepted)->toBeFalse();
    }
    $file = UploadedFile::fake()->createWithContent('fake.jpg', 'This is not a JPEG');
    $u = prepareAttachment($this->a, $this->direct, $file, 'image', 'fake-image');
    expect(fn () => $this->files->upload($this->a, $u['id'], $file))->toThrow(ContractException::class);
    config(['teamchat.attachments.max_bytes' => 2]);
    expect(fn () => prepareAttachment($this->a, $this->direct, UploadedFile::fake()->createWithContent('large.txt', 'Too large'), 'document', 'large'))->toThrow(ContractException::class);
});
it('recodes images bounds dimensions and retains no arbitrary trailing payload', function () {
    $file = UploadedFile::fake()->image('photo.png', 600, 400);
    file_put_contents($file->getPathname(), '<?php injected();', FILE_APPEND);
    $u = readyAttachment($this->a, $this->direct, $file, 'image');
    $row = $this->files->authorized($this->a, $u['id']);
    $bytes = Storage::disk('teamchat_private')->get($this->files->path($row));
    expect($u['mime'])->toBe('image/jpeg')->and($u['width'])->toBe(600)->and($bytes)->not->toContain('injected');
    config(['teamchat.attachments.max_pixels' => 100]);
    $bad = UploadedFile::fake()->image('big.png', 30, 30);
    $prepared = prepareAttachment($this->a, $this->direct, $bad, 'image', 'big');
    expect(fn () => $this->files->upload($this->a, $prepared['id'], $bad))->toThrow(ContractException::class);
});
it('protects tenant user conversation device and membership history boundaries', function () {
    $spaces = app(SpaceCommands::class);
    $group = $spaces->create($this->a, 'group', 'g', 'Group', '', [$this->b->userId, $this->c->userId]);
    $u = readyAttachment($this->a, $group, UploadedFile::fake()->createWithContent('old.txt', 'old'));
    $this->chat->send($this->a, $group, 'send', '', [$u['id']]);
    expect($this->files->status($this->b, $u['id'])['state'])->toBe('ready');
    $spaces->member($this->a, 'conversation', $group, 'remove', $this->b->userId, 'remove');
    expect(fn () => $this->files->status($this->b, $u['id']))->toThrow(ContractException::class);
    $spaces->member($this->a, 'conversation', $group, 'readd', $this->b->userId, 'add');
    expect(fn () => $this->files->status($this->b, $u['id']))->toThrow(ContractException::class);
    $foreign = realtimeActor(User::factory()->create(['institution_id' => Institution::factory()->create()->id]));
    expect(fn () => $this->files->status($foreign, $u['id']))->toThrow(ContractException::class);
    app(DeviceRegistry::class)->revoke($this->a, $this->a->deviceId);
    expect(fn () => $this->files->status($this->a, $u['id']))->toThrow(ContractException::class);
});
it('supports standard and private channels without exposing private pending uploads', function () {
    $spaces = app(SpaceCommands::class);
    $team = $spaces->create($this->a, 'team', 't', 'Team', '', [$this->b->userId, $this->c->userId]);
    $standard = DB::table('collab_chat_conversations')->where('team_id', $team)->value('id');
    $private = $spaces->create($this->a, 'channel', 'p', 'Private', '', [$this->b->userId], $team, 'private');
    foreach ([$standard, $private] as $n => $conversation) {
        $u = readyAttachment($this->a, $conversation, UploadedFile::fake()->createWithContent('notes.txt', 'notes'), 'document', 'u'.$n);
        $this->chat->send($this->a, $conversation, 'm'.$n, '', [$u['id']]);
        expect($this->files->status($this->b, $u['id'])['state'])->toBe('ready');
        if ($conversation === $private) {
            expect(fn () => $this->files->status($this->c, $u['id']))->toThrow(ContractException::class);
        }
    }
});
it('cancels and expires staged files while preserving attached history', function () {
    $u = readyAttachment($this->a, $this->direct, UploadedFile::fake()->createWithContent('a.txt', 'Alpha notes'));
    $this->files->cancel($this->a, $u['id']);
    expect($this->files->status($this->a, $u['id'])['state'])->toBe('cancelled');
    expect(fn () => $this->chat->send($this->a, $this->direct, 'cancelled', '', [$u['id']]))->toThrow(ContractException::class);
    $kept = readyAttachment($this->a, $this->direct, UploadedFile::fake()->createWithContent('b.txt', 'Beta notes'), 'document', 'keep');
    $this->chat->send($this->a, $this->direct, 'keep-message', '', [$kept['id']]);
    $this->travel(25)->hours();
    $this->files->prune($this->tenant->id);
    expect($this->files->status(realtimeActor($this->users[1]), $kept['id'])['state'])->toBe('ready');
});
it('downloads only through current authenticated authorization with private headers', function () {
    $u = readyAttachment($this->a, $this->direct, UploadedFile::fake()->createWithContent('notes.txt', 'Authorized download'));
    $this->chat->send($this->a, $this->direct, 'download', '', [$u['id']]);
    $user = $this->users[1];
    $this->actingAs($user)->withSession(['auth.mfa_user_id' => $user->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($user)]);
    $response = $this->get($u['content_url']);
    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('notes.txt');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(Storage::disk('public')->exists($this->files->path($this->files->authorized($this->a, $u['id']))))->toBeFalse();
    $this->chat->membership($this->b, $this->direct, false);
    $this->getJson($u['content_url'])->assertNotFound();
    $this->getJson('/api/v1/collaboration/uploads/../../config/content')->assertNotFound();
});
it('rolls back attachment association together with the message sequence and outbox', function () {
    $u = readyAttachment($this->a, $this->direct, UploadedFile::fake()->createWithContent('notes.txt', 'Transactional file'));
    $before = DB::table('collab_events')->count();
    DB::beginTransaction();
    $this->chat->send($this->a, $this->direct, 'rollback', '', [$u['id']]);
    DB::rollBack();
    expect(DB::table('collab_chat_messages')->count())->toBe(0)
        ->and(DB::table('collab_chat_uploads')->where('id', $u['id'])->value('message_id'))->toBeNull()
        ->and(DB::table('collab_events')->count())->toBe($before);
    expect($this->chat->send($this->a, $this->direct, 'rollback', '', [$u['id']]))->toBeString();
});
it('accepts PDF and Office packages but rejects active documents', function () {
    $pdf = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
    expect(readyAttachment($this->a, $this->direct, $pdf, 'document', 'pdf')['state'])->toBe('ready');
    $bad = UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n/JavaScript (evil)\n%%EOF");
    $u = prepareAttachment($this->a, $this->direct, $bad, 'document', 'badpdf');
    expect(fn () => $this->files->upload($this->a, $u['id'], $bad))->toThrow(ContractException::class);
    foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'] as $ext => $root) {
        $path = tempnam(sys_get_temp_dir(), 'office');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($root, '<document/>');
        $zip->close();
        try {
            $file = new UploadedFile($path, 'report.'.$ext, config('teamchat.attachments.types')[$ext][0], null, true);
            expect(readyAttachment($this->a, $this->direct, $file, 'document', $ext)['state'])->toBe('ready');
        } finally {
            unlink($path);
        }
    }
});
it('validates real audio streams and normalizes supported containers to a playable format', function () {
    foreach (['webm' => 'libopus', 'ogg' => 'libopus', 'mp4' => 'aac', 'wav' => 'pcm_s16le'] as $ext => $codec) {
        $path = tempnam(sys_get_temp_dir(), 'voice').'.'.$ext;
        $p = new \Symfony\Component\Process\Process([config('teamchat.attachments.ffmpeg'), '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', $codec, '-y', $path], timeout: 15);
        $p->mustRun();
        try {
            $mime = config('teamchat.attachments.types')[$ext][0];
            $file = new UploadedFile($path, 'voice.'.$ext, $mime, null, true);
            $u = readyAttachment($this->a, $this->direct, $file, 'voice', $ext);
            expect($u['mime'])->toBe('audio/mpeg')->and($u['duration_ms'])->toBeGreaterThan(900)->toBeLessThan(1300);
            $this->chat->send($this->a, $this->direct, 'voice-'.$ext, '', [$u['id']]);
        } finally {
            unlink($path);
        }
    }
    $fake = UploadedFile::fake()->createWithContent('voice.webm', 'Not an audio stream');
    $u = prepareAttachment($this->a, $this->direct, $fake, 'voice', 'fakevoice');
    expect(fn () => $this->files->upload($this->a, $u['id'], $fake))->toThrow(ContractException::class);
});
it('never labels absent antivirus as clean and fails closed when a scan is mandatory', function () {
    config(['teamchat.attachments.require_scan' => true]);
    $file = UploadedFile::fake()->createWithContent('safe.txt', 'not scanned');
    $u = prepareAttachment($this->a, $this->direct, $file);
    expect(fn () => $this->files->upload($this->a, $u['id'], $file))->toThrow(ContractException::class, 'media_unavailable');
    expect($this->files->status($this->a, $u['id'])['state'])->toBe('prepared');
});

it('preserves validated bytes when cancellation is rolled back by its caller', function () {
    $file = UploadedFile::fake()->createWithContent('rollback.txt', 'Validated private bytes');
    $upload = readyAttachment($this->a, $this->direct, $file);
    DB::beginTransaction();
    $this->files->cancel($this->a, $upload['id']);
    expect($this->files->status($this->a, $upload['id'])['state'])->toBe('cancelled');
    DB::rollBack();
    expect($this->files->status($this->a, $upload['id'])['state'])->toBe('ready');
    expect(Storage::disk('teamchat_private')->get($this->tenant->id.'/'.$upload['id'].'/content'))->toBe('Validated private bytes');
    $id = $this->chat->send($this->a, $this->direct, 'after-cancel-rollback', '', [$upload['id']]);
    expect($this->files->authorized($this->b, $upload['id'])->message_id)->toBe($id);
});
