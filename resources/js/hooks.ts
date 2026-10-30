import { useCallback, useEffect, useState } from 'react';

import { useAcl } from './ui';

/**
 * Load a `{data: T[]}` collection from the internal API, and reload it on demand.
 */
export function useCollection<T>(url: string) {
    const { api } = useAcl();
    const [items, setItems] = useState<T[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    const reload = useCallback(async () => {
        try {
            setItems((await api.get<{ data: T[] }>(url)).data);
            setError(null);
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'The request failed.');
        }
    }, [api, url]);

    useEffect(() => {
        void reload();
    }, [reload]);

    return { items, error, reload };
}
