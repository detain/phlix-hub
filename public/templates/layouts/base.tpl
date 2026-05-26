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
    </style>
</head>
<body>
    <header>
        <h1><a href="/" style="text-decoration: none; color: inherit;">Phlix Hub</a></h1>
        <nav>
            {if $is_authenticated|default:false}
                <a href="/my-servers">My Servers</a>
                <a href="/requests">Requests</a>
                {if $is_admin|default:false}
                    <a href="/admin/requests">Admin</a>
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
