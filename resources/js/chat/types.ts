export interface ChatUser {
  id: string;
  display_name: string;
  avatar_url: string | null;
}

export type LocalMessageStatus = 'failed' | 'pending' | 'sent';

export interface ChatMessage {
  id: string;
  sender_id: string | null;
  body: string;
  created_at: string;
  is_mine: boolean;
  client_message_id: string | null;
  local_status?: LocalMessageStatus;
}

export interface ChatConversation {
  id: string;
  other_user: ChatUser | null;
  latest_message: ChatMessage | null;
  unread_count: number;
  may_send: boolean;
  last_activity_at: string;
}

export interface ChatPageResponse<T> {
  success: boolean;
  data: T[];
  next_cursor: string | null;
}

export interface ChatEnvelope<T> {
  success: boolean;
  data: T;
}
