<?php

declare(strict_types=1);

/*
| Decisión de arquitectura: n8n NO trabaja con el CRM. Los flujos nuevos (asesor multicanal,
| formularios publicitarios, despacho persistente, prueba del asesor) no llaman a n8n ni usan los
| endpoints heredados de captación (/api/v1/leads/intake, /api/v1/incompany/lead), que siguen
| intactos pero fuera de la captación nueva. Sus únicas salidas HTTP van a Meta (graph.facebook.com).
*/

/** @return array<int, string> */
function newFlowFiles(): array
{
    $base = dirname(__DIR__, 3);

    return array_map(fn (string $f): string => $base.'/'.$f, [
        'Modules/Ai/app/Services/AdvisorTurnService.php',
        'Modules/Ai/app/Services/AdvisorPromptBuilder.php',
        'Modules/Ai/app/Livewire/Advisor/Preview.php',
        'Modules/Chat/app/Http/Controllers/WidgetController.php',
        'Modules/Social/app/Services/SocialAdvisorResponder.php',
        'Modules/Social/app/Services/SocialAutomationService.php',
        'Modules/Social/app/Services/MetaLeadFormService.php',
        'Modules/Social/app/Services/MetaLeadAccessCheck.php',
        'Modules/Social/app/Jobs/RespondWithAdvisor.php',
        'Modules/Social/app/Support/AdvisorDispatcher.php',
        'Modules/Social/app/Console/AdvisorWorkerCommand.php',
        'Modules/Social/app/Console/MetaLeadCheckCommand.php',
        'Modules/Social/app/Livewire/LeadForms.php',
    ]);
}

it('los flujos nuevos no referencian n8n ni los endpoints heredados de captación', function () {
    foreach (newFlowFiles() as $file) {
        $code = (string) file_get_contents($file);
        foreach (['n8n', 'leads/intake', 'incompany/lead', 'LeadIntakeController', 'IncompanyLeadIntake', 'IncompanyController', 'testN8n', 'automation.mcaschool'] as $needle) {
            expect(stripos($code, $needle))->toBeFalse("{$needle} aparece en ".basename($file));
        }
    }
});

it('las únicas salidas HTTP de los flujos nuevos van a Meta (graph.facebook.com)', function () {
    foreach (newFlowFiles() as $file) {
        preg_match_all('#https?://([a-z0-9.-]+)#i', (string) file_get_contents($file), $m);
        foreach (array_unique($m[1]) as $host) {
            expect($host)->toBe('graph.facebook.com', basename($file)." apunta a {$host}");
        }
    }
});
