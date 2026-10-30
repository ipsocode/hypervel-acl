import { useCallback, useEffect, useRef, useState } from 'react';

import { useAcl } from './ui';

/**
 * Load a `{data: T[]}` collection from the internal API, and reload it on demand.
 * Only the latest request may set the state, so a slow answer for a previous workspace is dropped.
 */
export function useCollection<T>(url: string) {
    const { api } = useAcl();
    const [items, setItems] = useState<T[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const latest = useRef(0);

    const reload = useCallback(async () => {
        const request = ++latest.current;

        try {
            const data = (await api.get<{ data: T[] }>(url)).data;

            if (request === latest.current) {
                setItems(data);
                setError(null);
            }
        } catch (failure) {
            if (request === latest.current) {
                setError(failure instanceof Error ? failure.message : 'The request failed.');
            }
        }
    }, [api, url]);

    useEffect(() => {
        void reload();

        // Answers still in flight when the screen goes away are dropped.
        return () => {
            latest.current++;
        };
    }, [reload]);

    return { items, error, reload };
}
