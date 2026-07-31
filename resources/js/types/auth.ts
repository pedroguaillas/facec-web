export type UserType = {
    id: number;
    type: string;
};

export type User = {
    id: number;
    name: string | null;
    user: string;
    email: string;
    avatar: string | null;
    user_type_id: number;
    type: UserType | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
