<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\Http\Request;

/**
 * Migas de pan de la barra superior: UN solo mapa de rutas (sección del menú + página), en
 * lugar de títulos genéricos por pantalla. La sección enlaza a su listado cuando se está en
 * una página interior; la página actual no es enlace (aria-current). Solo presentación: no
 * crea ni cambia rutas, y una ruta sin entrada cae a «Inicio» de forma segura.
 */
final class Breadcrumbs
{
    /**
     * Secciones del menú: patrón de ruta => [icono, etiqueta, ruta del listado].
     * El orden importa: el primer patrón que coincide gana.
     *
     * @var array<int,array{0:string|array<int,string>,1:string,2:string,3:string}>
     */
    private const SECTIONS = [
        ['dashboard', 'home', 'Inicio', 'dashboard'],
        ['teamchat.*', 'message-circle', 'teamchat::chat.title', 'teamchat.index'],
        ['crm.leads.*', 'users', 'Leads', 'crm.leads.index'],
        ['crm.pipeline', 'funnel', 'Pipeline Comercial', 'crm.pipeline'],
        [['crm.contacts.*', 'crm.conversations.*'], 'contact', 'Contactos', 'crm.contacts.index'],
        ['crm.calendar-templates', 'calendar', 'Plantillas de Calendario', 'crm.calendar-templates'],
        ['crm.agenda.*', 'calendar', 'Calendario', 'crm.agenda.index'],
        ['email-templates.mine', 'mail', 'Mis plantillas', 'email-templates.mine'],
        ['social.inbox', 'inbox', 'Bandeja social', 'social.inbox'],
        ['social.publisher*', 'image', 'Publicador', 'social.publisher'],
        ['social.wa-templates', 'file-text', 'Plantillas de WhatsApp', 'social.wa-templates'],
        [['social.channels', 'social.lead-forms', 'social.meta'], 'message-circle', 'Canales sociales', 'social.channels'],
        [['advisors.*', 'ai.advisor.*'], 'bot', 'Asesores Inteligentes', 'advisors.index'],
        ['ai.knowledge.*', 'book-open', 'Centro de Conocimiento', 'ai.knowledge.library'],
        ['catalog.*', 'book-open', 'Catálogo', 'catalog.programs.index'],
        ['users.*', 'user-cog', 'Usuarios', 'users.index'],
        ['mcp.admin', 'bot', 'IA / MCP', 'mcp.admin'],
        ['integrations.*', 'plug', 'Integraciones', 'integrations.index'],
        ['audit.*', 'shield', 'Auditoría', 'audit.index'],
        ['settings.*', 'user-cog', 'Ajustes', 'settings.index'],
        ['profile.*', 'user', 'Mi perfil', 'profile.me'],
    ];

    /** Páginas interiores: ruta => etiqueta (las rutas que no aparecen son el propio listado). */
    private const PAGES = [
        'crm.leads.create' => 'Nuevo lead',
        'crm.leads.show' => 'Ficha del lead',
        'crm.contacts.show' => 'Ficha del contacto',
        'crm.conversations.show' => 'Conversación',
        'crm.agenda.create' => 'Nueva actividad',
        'crm.agenda.edit' => 'Editar actividad',
        'crm.agenda.show' => 'Actividad',
        'crm.agenda.availability' => 'Disponibilidad',
        'crm.agenda.profile' => 'Perfil de reuniones',
        'social.publisher.history' => 'Historial',
        'social.publisher.scheduled' => 'Programadas',
        'social.publisher.calendar' => 'Calendario editorial',
        'social.lead-forms' => 'Formularios publicitarios',
        'social.meta' => 'Meta',
        'advisors.create' => 'Nuevo asesor',
        'advisors.edit' => 'Configurar asesor',
        'ai.advisor.configure' => 'Ficha del asesor',
        'ai.knowledge.agents' => 'Por agente',
        'ai.knowledge.index' => 'Conocimiento',
        'catalog.programs.create' => 'Nuevo programa',
        'catalog.programs.edit' => 'Editar programa',
        'catalog.categories' => 'Categorías',
        'users.create' => 'Nuevo usuario',
        'users.edit' => 'Editar usuario',
        'integrations.configure' => 'Configurar',
        'integrations.ai-processes' => 'Procesos de IA',
        'integrations.video' => 'Videoconferencias',
        'settings.email-senders' => 'Remitentes de correo',
        'settings.email-templates' => 'Plantillas de correo',
        'profile.edit' => 'Datos de la cuenta',
    ];

    /**
     * @return array{icon:string, items:array<int,array{label:string, url:string|null}>}
     */
    public static function for(Request $request): array
    {
        foreach (self::SECTIONS as [$patterns, $icon, $label, $route]) {
            if (! $request->routeIs(...(array) $patterns)) {
                continue;
            }
            $page = self::PAGES[(string) $request->route()?->getName()] ?? null;
            if ($page === null) {
                return ['icon' => $icon, 'items' => [['label' => __($label), 'url' => null]]];
            }

            return ['icon' => $icon, 'items' => [
                ['label' => __($label), 'url' => route($route)],
                ['label' => __($page), 'url' => null],
            ]];
        }

        return ['icon' => 'home', 'items' => [['label' => __('Inicio'), 'url' => null]]];
    }
}
