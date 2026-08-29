from __future__ import annotations

from pydantic import BaseModel, Field


class ContextExcerpt(BaseModel):
    source: str
    title: str
    content: str
    kind: str


class SkillContext(BaseModel):
    name: str
    path: str
    description: str = ""
    excerpt: str = ""


class ContextPacket(BaseModel):
    selected_files: list[str] = Field(default_factory=list)
    agents: list[ContextExcerpt] = Field(default_factory=list)
    rules: list[ContextExcerpt] = Field(default_factory=list)
    skills: list[SkillContext] = Field(default_factory=list)
    prd: list[ContextExcerpt] = Field(default_factory=list)
    audit: list[ContextExcerpt] = Field(default_factory=list)
    tooling: dict[str, object] = Field(default_factory=dict)
    sources: list[str] = Field(default_factory=list)
    skipped: list[str] = Field(default_factory=list)
    budget_chars: int = 16000
    used_chars: int = 0

