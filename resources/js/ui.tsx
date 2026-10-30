import { type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, createContext, useContext } from 'react';

import type { Api } from './api';
import { ApiError } from './api';
import type { Boot, Errors } from './types';

export const AclContext = createContext<{ api: Api; boot: Boot } | null>(null);

export function useAcl() {
    const context = useContext(AclContext);

    if (context === null) {
        throw new Error('The ACL pages render inside the app.');
    }

    return context;
}

export function errorsOf(error: unknown): Errors {
    if (error instanceof ApiError) {
        return Object.keys(error.errors).length > 0 ? error.errors : { _: [error.message] };
    }

    throw error;
}

const field = 'block w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100';

export function Card({ title, actions, children }: { title?: string; actions?: ReactNode; children: ReactNode }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            {title && (
                <header className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                    <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">{title}</h2>
                    {actions}
                </header>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

export function Button({ variant = 'primary', className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' }) {
    const styles = {
        primary: 'bg-indigo-600 text-white hover:bg-indigo-500',
        secondary: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700',
        danger: 'bg-red-600 text-white hover:bg-red-500',
    };

    return <button type="button" className={`rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50 ${styles[variant]} ${className}`} {...props} />;
}

export function Field({ label, errors, name, children }: { label: string; errors?: Errors; name: string; children: ReactNode }) {
    return (
        <label className="block space-y-1">
            <span className="text-xs font-medium text-slate-600 dark:text-slate-400">{label}</span>
            {children}
            {errors?.[name]?.map((message) => (
                <span key={message} className="block text-xs text-red-600">
                    {message}
                </span>
            ))}
        </label>
    );
}

export function Input(props: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={field} {...props} />;
}

export function Select(props: SelectHTMLAttributes<HTMLSelectElement>) {
    return <select className={field} {...props} />;
}

export function Badge({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'green' | 'red' | 'indigo' }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        green: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        red: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
    };

    return <span className={`inline-block rounded px-1.5 py-0.5 text-xs font-medium ${tones[tone]}`}>{children}</span>;
}

export function FormError({ errors }: { errors: Errors }) {
    return errors._ ? <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{errors._.join(' ')}</p> : null;
}

export function Empty({ children }: { children: ReactNode }) {
    return <p className="py-6 text-center text-sm text-slate-500">{children}</p>;
}
