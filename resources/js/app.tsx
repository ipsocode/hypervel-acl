import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import '../css/app.css';
import Acl from './Acl';
import type { Boot } from './types';

declare global {
    interface Window {
        Acl: Boot;
    }
}

// The bundled frontend: the Blade shell mounts it and the browser supplies the URL.
function Bundled() {
    const [pathname, setPathname] = useState(window.location.pathname);

    useEffect(() => {
        const sync = () => setPathname(window.location.pathname);

        window.addEventListener('popstate', sync);

        return () => window.removeEventListener('popstate', sync);
    }, []);

    return (
        <Acl
            boot={window.Acl}
            pathname={pathname}
            navigate={(url) => {
                window.history.pushState(null, '', url);
                setPathname(url);
            }}
        />
    );
}

createRoot(document.getElementById('acl')!).render(
    <StrictMode>
        <Bundled />
    </StrictMode>,
);
