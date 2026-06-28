{* Double-submit CSRF token. The sentinel value is replaced with the live
   per-session token by Phlix\Hub\Http\Middleware\CsrfMiddleware::issue()
   before the response leaves the worker. *}<input type="hidden" name="_csrf" value="__PHLIX_CSRF_TOKEN__">
