{{--
  Sidebar del shell autenticado (shell_dashboard_v4). Se incluye dentro del x-data
  del layout (Alpine `sidebarOpen` para el colapso móvil). Grupos de navegación
  delimitados; conserva los mismos enlaces y gates (@can), el estado activo por
  ruta, el acceso a "Mi perfil" y el logout (form POST). El menú de perfil abre
  hacia arriba con Alpine (x-data local).
--}}
@php
    $u = Auth::user();
    $parts = preg_split('/\s+/', trim((string) $u->name)) ?: [];
    $navInitials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1)) ?: mb_strtoupper(mb_substr((string) $u->name, 0, 1));
    $navAvatar = method_exists($u, 'avatarUrl') ? $u->avatarUrl() : null;
    // Logo institucional (marca del panel): se usa si se ha subido uno en Ajustes.
    $__ctx = app(\Modules\Core\Tenancy\CurrentInstitution::class);
    $brandInstitution = $__ctx->has() ? \Modules\Institutions\Models\Institution::find($__ctx->id()) : null;
    $brandLogo = $brandInstitution?->logoUrl();
@endphp
<aside class="mca-sidebar" :class="{ open: sidebarOpen }">
    <div class="mca-brand">
        @if ($brandLogo)
            <img src="{{ $brandLogo }}" alt="{{ $brandInstitution->name }}" class="brand-logo" style="max-height:40px;max-width:150px;object-fit:contain">
        @else
            <div class="brand-mark"><x-ui.icon name="shield" /></div>
            <div>
                <div class="brand-name">MCA School</div>
                <div class="brand-sub">PANEL · CRM</div>
            </div>
        @endif
    </div>

    <div class="mca-brand-gap"></div>

    {{-- Selector de línea de producto: preparado para varias; hoy una sola --}}
    <div class="mca-switcher" title="Línea de producto activa">
        <div class="sic"><x-ui.icon name="layers" /></div>
        <div class="tx">
            <div class="t1">Microcredenciales</div>
            <div class="t2">Línea activa</div>
        </div>
        <x-ui.icon name="chevron-down" class="chev" style="width:16px;height:16px" />
    </div>

    <nav class="mca-nav">
        <div class="mca-nav-group">
            <div class="mca-nav-label">CAPTACIÓN</div>
            <a href="{{ route('dashboard') }}" class="mca-nav-item {{ request()->routeIs('dashboard') ? 'on' : '' }}">
                <x-ui.icon name="home" /> {{ __('Inicio') }}
            </a>
            @can('viewAny', \Modules\Crm\Models\Lead::class)
                <a href="{{ route('crm.leads.index') }}" class="mca-nav-item {{ request()->routeIs('crm.leads.*') ? 'on' : '' }}">
                    <x-ui.icon name="users" /> {{ __('Leads') }}
                </a>
                <a href="{{ route('crm.contacts.index') }}" class="mca-nav-item {{ request()->routeIs('crm.contacts.*') || request()->routeIs('crm.conversations.*') ? 'on' : '' }}">
                    <x-ui.icon name="contact" /> {{ __('Contactos') }}
                </a>
            @endcan
        </div>

        <div class="mca-nav-group">
            <div class="mca-nav-label">CONFIGURACIÓN</div>
            <a href="{{ route('advisors.index') }}" class="mca-nav-item {{ request()->routeIs('advisors.*') || request()->routeIs('ai.*') ? 'on' : '' }}">
                <x-ui.icon name="sparkles" /> {{ __('Asesores Inteligentes') }}
            </a>
            @can('viewAny', \Modules\Catalog\Models\Program::class)
                <a href="{{ route('catalog.programs.index') }}" class="mca-nav-item {{ request()->routeIs('catalog.*') ? 'on' : '' }}">
                    <x-ui.icon name="book-open" /> {{ __('Catálogo') }}
                </a>
            @endcan
            @can('viewAny', \App\Models\User::class)
                <a href="{{ route('users.index') }}" class="mca-nav-item {{ request()->routeIs('users.*') ? 'on' : '' }}">
                    <x-ui.icon name="user-cog" /> {{ __('Usuarios') }}
                </a>
            @endcan
            @can('viewAny', \Modules\Integrations\Models\Integration::class)
                <a href="{{ route('integrations.index') }}" class="mca-nav-item {{ request()->routeIs('integrations.*') ? 'on' : '' }}">
                    <x-ui.icon name="plug" /> {{ __('Integraciones') }}
                </a>
            @endcan
            @can('viewAny', \Modules\Audit\Models\AuditLog::class)
                <a href="{{ route('audit.index') }}" class="mca-nav-item {{ request()->routeIs('audit.*') ? 'on' : '' }}">
                    <x-ui.icon name="shield" /> {{ __('Auditoría') }}
                </a>
            @endcan
            @if (auth()->user()?->canManageSettings())
                <a href="{{ route('settings.index') }}" class="mca-nav-item {{ request()->routeIs('settings.*') ? 'on' : '' }}">
                    <x-ui.icon name="user-cog" /> {{ __('Ajustes') }}
                </a>
            @endif
        </div>
    </nav>

    {{-- Pie: usuario + menú (Mi perfil / Cerrar sesión) --}}
    <div class="mca-sidebar-foot" x-data="{ userMenu: false }" @click.outside="userMenu = false">
        <button type="button" class="mca-user" @click="userMenu = ! userMenu" aria-haspopup="true" :aria-expanded="userMenu">
            <span class="av">
                @if ($navAvatar)
                    <img src="{{ $navAvatar }}" alt="{{ $u->name }}">
                @else
                    {{ $navInitials }}
                @endif
            </span>
            <div style="flex:1;min-width:0">
                <div class="t1">{{ $u->name }}</div>
                <div class="t2">{{ $u->role->label() }}</div>
            </div>
            <x-ui.icon name="chevron-up" class="chev" style="width:16px;height:16px" />
        </button>

        <div class="mca-user-menu" x-show="userMenu" x-transition x-cloak style="display:none">
            <a href="{{ route('profile.me') }}"><x-ui.icon name="user" /> {{ __('Mi perfil') }}</a>
            <div class="sep"></div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><x-ui.icon name="power" /> {{ __('Cerrar sesión') }}</button>
            </form>
        </div>
    </div>
</aside>
