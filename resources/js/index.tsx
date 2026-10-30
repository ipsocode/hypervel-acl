import { router, usePage } from '@inertiajs/react';

import Acl from './Acl';
import type { Boot } from './types';

// The Inertia frontend: the application's layout and Inertia supply the URL and the navigation.
export default function Index(boot: Boot) {
    const { url } = usePage();

    return <Acl boot={boot} pathname={url.split('?')[0]} navigate={(target) => router.visit(target)} />;
}
