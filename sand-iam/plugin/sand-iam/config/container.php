<?php

// Webman resolves this plugin's service container via App::container($plugin).
// It must return a PSR-11 container instance (Webman\Container implements
// ContainerInterface with ->get()/->make()), matching sandadmin and every
// other plugin's config/container.php. Returning an empty array left any
// code path that calls App::container('sand-iam')->make()/->get() — notably
// App::exceptionResponse() rendering a route-not-found exception for any
// unmatched /app/sand-iam/admin/* URL — crashing with the opaque fatal
// "Call to a member function make() on array" instead of a clean 404.
return new Webman\Container();
