@extends('backend.master')

@section('content')
    <div class="flex items-center justify-center min-h-[70vh] px-4">
        <div class="max-w-md w-full bg-white rounded-2xl shadow-lg border border-slate-200 p-8 text-center">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-amber-500" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 9v3.75m0 3.75h.008M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                </svg>
            </div>

            <h2 class="text-xl font-semibold text-slate-800 mb-2">No warehouse assigned</h2>

            <p class="text-slate-500 leading-relaxed mb-6">
                Your account isn’t linked to any warehouse yet, so the Sale screen can’t
                load stock or complete a sale. Ask an administrator to assign a warehouse
                to your account, then reload this page.
            </p>

            @if (auth()->user()->role === 'admin')
                <div class="text-left text-sm text-slate-600 bg-slate-50 rounded-lg p-4 mb-6">
                    <p class="font-medium text-slate-700 mb-1">As an administrator you can fix this yourself:</p>
                    <ol class="list-decimal list-inside space-y-1">
                        <li>Create a warehouse in <span class="font-medium">Manage Warehouse</span> (if none exists).</li>
                        <li>Open <span class="font-medium">Manage Users</span> → your account → assign the warehouse.</li>
                    </ol>
                </div>
            @endif

            <div class="flex items-center justify-center gap-3">
                <a href="{{ url('/Sale') }}"
                    class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-800 text-white text-sm font-medium hover:bg-slate-700">
                    Reload
                </a>
                <a href="{{ url('/logout') }}"
                    class="inline-flex items-center px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:bg-slate-50">
                    Logout
                </a>
            </div>
        </div>
    </div>
@endsection
