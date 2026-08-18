<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-2 px-3.5 py-2 bg-[#1E5AA8] border border-transparent rounded-lg text-sm font-medium text-white shadow-sm hover:bg-[#194E93] focus:outline-none focus:ring-2 focus:ring-[#1E5AA8] focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
