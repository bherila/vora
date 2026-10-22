import { readInitialData } from '@/initialData';

export type InstanceModule = 'events' | 'personas' | 'stories' | 'chat' | 'community' | 'profiles' | 'interests' | 'library' | 'favorites';

export interface InstanceBootstrap {
  instance?: {
    profile?: 'vora' | 'event';
    modules?: Partial<Record<InstanceModule, boolean>>;
    home_path?: string;
  };
}

/** Existing installations keep their modules when optional bootstrap is absent. */
export function moduleEnabled(name: InstanceModule): boolean {
  return readInitialData<InstanceBootstrap>().instance?.modules?.[name] ?? name !== 'events';
}

export function instanceProfile(): 'vora' | 'event' {
  return readInitialData<InstanceBootstrap>().instance?.profile ?? 'vora';
}
