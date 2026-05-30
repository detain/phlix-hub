<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{block name="title"}Phlix Hub{/block}</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1e1e2e; background: #fafafa; }
        header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #ddd; }
        header h1 { font-size: 1.25rem; margin: 0; }
        header nav a { margin-left: 1rem; color: #4f46e5; text-decoration: none; }
        header nav a:hover { text-decoration: underline; }
        main { padding-top: 2rem; }
        form { display: flex; flex-direction: column; gap: 0.75rem; max-width: 360px; }
        label { font-weight: 600; }
        input[type=text], input[type=email], input[type=password] { padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font: inherit; }
        button { padding: 0.6rem 1rem; background: #4f46e5; color: white; border: 0; border-radius: 4px; cursor: pointer; font: inherit; }
        button:hover { background: #4338ca; }
        .error { padding: 0.75rem; background: #fee; border: 1px solid #f99; border-radius: 4px; color: #c00; margin-bottom: 1rem; }
        .empty-state { text-align: center; padding: 3rem 1rem; color: #666; border: 2px dashed #ddd; border-radius: 8px; }
        .logout-form { display: inline; }
        .logout-form button { background: transparent; color: #4f46e5; padding: 0; font: inherit; cursor: pointer; }
        .logout-form button:hover { text-decoration: underline; }
        .btn { padding: 0.6rem 1rem; background: #4f46e5; color: white; border: 0; border-radius: 4px; cursor: pointer; font: inherit; text-decoration: none; display: inline-block; }
        .btn:hover { background: #4338ca; }
        .btn.btn-primary { background: #4f46e5; }
        .btn.btn-primary:hover { background: #4338ca; }
        .btn.btn-secondary { background: #6b7280; }
        .btn.btn-secondary:hover { background: #4b5563; }
        .btn.btn-small { padding: 0.3rem 0.6rem; font-size: 0.875rem; }
        .btn.btn-warning { background: #f59e0b; }
        .btn.btn-warning:hover { background: #d97706; }

        /* Modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .modal { background: white; border-radius: 8px; padding: 1.5rem; max-width: 420px; width: 90%; box-shadow: 0 4px 24px rgba(0,0,0,0.15); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .modal-header h2 { margin: 0; font-size: 1.25rem; }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0; line-height: 1; }
        .modal-close:hover { color: #333; }
        .modal .form-group { margin-bottom: 1rem; }
        .modal label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
        .modal select, .modal input[type=text], .modal input[type=email] { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font: inherit; box-sizing: border-box; }
        .radio-group { display: flex; gap: 1rem; }
        .radio-label { font-weight: normal; display: flex; align-items: center; gap: 0.25rem; cursor: pointer; }
        .form-actions { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; }

        /* Permission badge */
        .permission-badge { padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600; }
        .permission-read { background: #dbeafe; color: #1e40af; }
        .permission-readwrite { background: #d1fae5; color: #065f46; }

        /* Table */
        .shares-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .shares-table th, .shares-table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        .shares-table th { font-weight: 600; color: #374151; background: #f9fafb; }
        .shares-table tr:hover { background: #f9fafb; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .page-header h1 { margin: 0; }
        .header-actions { display: flex; gap: 0.5rem; }

        /* Permission select */
        .permission-select { padding: 0.25rem 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.875rem; }

        /* Shared libraries cards */
        .library-list { display: flex; flex-direction: column; gap: 1rem; }
        .shared-library-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .shared-library-card:hover { border-color: #4f46e5; }
        .library-info h2 { margin: 0 0 0.5rem; font-size: 1.1rem; }
        .owner-info { color: #6b7280; font-size: 0.9rem; margin: 0.25rem 0; }
    </style>
</head>
<body>
    <header>
        <h1><a href="/" style="text-decoration: none; color: inherit;">Phlix Hub</a></h1>
        <nav>
            {if $is_authenticated|default:false}
                <a href="/my-servers">Servers</a>
                <a href="/my-servers">My Servers</a>
                <a href="/invite-links">Invite Links</a>
                <a href="/requests">Requests</a>
                <a href="/manage-shares">Manage Shares</a>
                <a href="/shared-with-me">Shared With Me</a>
                {if $is_admin|default:false}
                    <a href="/admin/requests">Admin</a>
                    <a href="/hub-settings">Hub Settings</a>
                {/if}
                <form method="post" action="/logout" class="logout-form"><button type="submit">Log out</button></form>
            {else}
                <a href="/login">Log in</a>
                <a href="/signup">Sign up</a>
            {/if}
        </nav>
    </header>
    <main>
        {block name="content"}{/block}
    </main>
    {block name="scripts"}{/block}
</body>
</html>
