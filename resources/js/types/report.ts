import type { Money } from './generation';

// One line of the admin spend report.
export type SpendTotals = {
    // Generations that succeeded. Failed ones cost nothing and aren't counted.
    generations: number;
    // What users were charged.
    spend: Money;
    // What Pixelforge charged us.
    provider_cost: Money;
    // The difference.
    profit: Money;
};

export type SpendUserLine = SpendTotals & {
    // Null once the user has deleted their account.
    user: { id: number; name: string; email: string } | null;
};

export type SpendDay = SpendTotals & {
    // A UTC day, "2026-11-04".
    date: string;
    // What Pixelforge's own usage report says it billed us that day, and
    // whether our provider cost agrees. Null if the report couldn't be fetched.
    pixelforge_cost: Money | null;
    matches: boolean | null;
    users: SpendUserLine[];
};

export type SpendReport = {
    days: SpendDay[];
    users: SpendUserLine[];
    totals: SpendTotals;
};
