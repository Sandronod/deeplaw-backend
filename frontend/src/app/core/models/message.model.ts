export type MessageRole      = 'user' | 'assistant' | 'system';
export type ConfidenceLevel  = 'high' | 'medium' | 'low' | 'none';
export type AnswerMode       = 'find' | 'summarize' | 'compare' | 'explain' | 'advise' | 'chat';
export type MessageStatus    = 'loading' | 'streaming' | 'done' | 'error';
export type StreamPhase      = 'searching' | 'law_lookup' | 'case_retrieval' | 'reranking' | 'writing';

// ── Citation types ────────────────────────────────────────────────────────────

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
  case_type: string | null;
  url: string | null;
}

export interface LawCitation {
  type: 'law';
  law_id: number;
  article_id: number;
  title: string;
  article_num: string;
  article_title: string;
  excerpt: string;
  similarity: number;
  url: string;
}

export interface MatsneCitation {
  type: 'matsne';
  matsne_id: number;
  title: string;
  doc_type: string | null;
  issuer: string | null;
  is_active: boolean;
  effective_from_year: number | null;
  effective_to_year: number | null;
  excerpt: string;
  similarity: number;
  url: string;
}

export interface EuCitation {
  type: 'eu';
  cellar_id: string;
  doc_type: string;
  source: 'legislation' | 'case_law';
  court: string | null;
  case_num: string | null;
  title: string | null;
  doc_date: string | null;
  excerpt: string;
  similarity: number;
  url: string | null;
}

export interface GermanCitation {
  type: 'german';
  case_id: number;
  external_id: string | null;
  court_name: string | null;
  level_of_appeal: string | null;
  jurisdiction: string | null;
  date_year: number | null;
  excerpt: string;
  similarity: number;
}

export interface ConstCourtCitation {
  type: 'const_court';
  legal_id: number;
  case_number: string | null;
  case_name: string | null;
  decision_type: string | null;
  decision_date: string | null;
  college: string | null;
  respondent: string | null;
  result: string | null;
  excerpt: string;
  similarity: number;
  url: string;
}

/** Reserved for future ECHR integration */
export interface EchrCitation {
  type: 'echr';
  application_no: string;
  case_name: string;
  judgment_date: string | null;
  articles_violated: string[];
  excerpt: string;
  url: string;
}

// ── Meta ──────────────────────────────────────────────────────────────────────

export interface MessageMeta {
  retrieval_mode: string | null;
  answer_mode: AnswerMode | null;

  /** Overall confidence (domestic + law combined) */
  confidence: ConfidenceLevel | null;
  confidence_note: string | null;

  /** Per-source confidence breakdown */
  overall_confidence?: ConfidenceLevel | null;
  domestic_confidence?: ConfidenceLevel | null;
  law_confidence?: ConfidenceLevel | null;
  echr_confidence?: ConfidenceLevel | null;

  /** Citation counts */
  used_case_count: number;
  used_chunk_count: number;
  law_citation_count?: number;

  pipeline_ms?: number | null;
}

// ── Chat message ──────────────────────────────────────────────────────────────

export interface ChatMessage {
  id: number;
  chat_id: number;
  role: MessageRole;
  content: string;
  citations: Citation[];
  law_citations?: LawCitation[];
  echr_citations?: EchrCitation[];
  matsne_citations?: MatsneCitation[];
  eu_citations?: EuCitation[];
  german_citations?: GermanCitation[];
  const_court_citations?: ConstCourtCitation[];
  meta?: MessageMeta;
  created_at: string;
  // UI-only fields — not persisted
  status?: MessageStatus;
  isPartial?: boolean;   // true if streaming was interrupted by an error
  canRetry?: boolean;    // true if partial content exists and retry is possible
  isNew?: boolean;       // true for messages created this session (not loaded from history)
}

// ── SSE events ────────────────────────────────────────────────────────────────

export interface SseEvent {
  event: 'status' | 'token' | 'done' | 'error';
  data: SseStatusData | SseTokenData | SseDoneData | SseErrorData;
}

export interface SseStatusData {
  phase: StreamPhase;
}

export interface SseTokenData {
  token: string;
}

export interface SseDoneData {
  message_id: number;
  citations: Citation[];
  law_citations?: LawCitation[];
  echr_citations?: EchrCitation[];
  matsne_citations?: MatsneCitation[];
  eu_citations?: EuCitation[];
  german_citations?: GermanCitation[];
  const_court_citations?: ConstCourtCitation[];
  meta: MessageMeta;
}

export interface SseErrorData {
  message: string;
}
