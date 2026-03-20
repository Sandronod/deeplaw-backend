export type MessageRole = 'user' | 'assistant' | 'system';

export interface Citation {
  case_id: number;
  case_num: string | null;
  case_date: string | null;
  court: string | null;
  chamber: string | null;
  category: string | null;
  dispute_subject: string | null;
  result: string | null;
  relevance_score: number | null;
  url: string | null;
}

export interface MessageMeta {
  retrieval_mode: string | null;
  used_case_count: number;
  used_chunk_count: number;
}

export interface ChatMessage {
  id: number;
  chat_id: number;
  role: MessageRole;
  content: string;
  citations: Citation[];
  meta?: MessageMeta;
  created_at: string;
}
