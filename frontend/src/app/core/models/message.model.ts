export type MessageRole      = 'user' | 'assistant' | 'system';
export type ConfidenceLevel  = 'high' | 'medium' | 'low' | 'none';
export type AnswerMode       = 'find' | 'summarize' | 'compare' | 'explain' | 'advise' | 'chat';
export type MessageStatus    = 'loading' | 'streaming' | 'done' | 'error';

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
  answer_mode: AnswerMode | null;
  confidence: ConfidenceLevel | null;
  confidence_note: string | null;
  used_case_count: number;
  used_chunk_count: number;
  pipeline_ms?: number | null;
}

export interface ChatMessage {
  id: number;
  chat_id: number;
  role: MessageRole;
  content: string;
  citations: Citation[];
  meta?: MessageMeta;
  created_at: string;
  // UI-only fields — not persisted
  status?: MessageStatus;
  isPartial?: boolean;   // true if streaming was interrupted by an error
  isNew?: boolean;       // true for messages created this session (not loaded from history)
}

/** SSE event received from the stream endpoint */
export interface SseEvent {
  event: 'status' | 'token' | 'done' | 'error';
  data: SseStatusData | SseTokenData | SseDoneData | SseErrorData;
}

export interface SseStatusData { phase: 'searching' | 'writing'; }
export interface SseTokenData  { token: string; }
export interface SseDoneData   {
  message_id: number;
  citations: Citation[];
  meta: MessageMeta;
}
export interface SseErrorData  { message: string; }
