from __future__ import annotations

import asyncio
import os
import re
from typing import Any
from urllib.parse import unquote

import httpx
from dotenv import load_dotenv
from fastapi import FastAPI
from pydantic import BaseModel, Field

load_dotenv()

app = FastAPI(title="Sahada Crawler", version="1.0.0")

GOOGLE_PLACES_API_KEY = os.getenv("GOOGLE_PLACES_API_KEY", "")
REQUEST_DELAY_SECONDS = float(os.getenv("CRAWLER_DELAY", "1.5"))


class CrawlRequest(BaseModel):
    maps_url: str
    query: str | None = None
    limit: int = Field(default=40, ge=1, le=120)


def extract_query(maps_url: str, fallback: str | None = None) -> str:
    match = re.search(r"/maps/search/([^/@?]+)", maps_url)
    if match:
        return unquote(match.group(1).replace("+", " "))
    match = re.search(r"[?&]q=([^&]+)", maps_url)
    if match:
        return unquote(match.group(1).replace("+", " "))
    return fallback or "kafe istanbul"


async def places_text_search(query: str, limit: int) -> list[dict[str, Any]]:
    if not GOOGLE_PLACES_API_KEY:
        return []

    results: list[dict[str, Any]] = []
    url = "https://maps.googleapis.com/maps/api/place/textsearch/json"
    params: dict[str, str] = {
        "query": query,
        "key": GOOGLE_PLACES_API_KEY,
        "language": "tr",
        "region": "tr",
    }

    async with httpx.AsyncClient(timeout=30.0) as client:
        while len(results) < limit:
            response = await client.get(url, params=params)
            response.raise_for_status()
            payload = response.json()
            for row in payload.get("results", []):
                results.append(
                    {
                        "name": row.get("name"),
                        "category": (row.get("types") or ["establishment"])[0],
                        "address": row.get("formatted_address"),
                        "city": "İstanbul",
                        "district": None,
                        "place_id": row.get("place_id"),
                        "latitude": (row.get("geometry") or {}).get("location", {}).get("lat"),
                        "longitude": (row.get("geometry") or {}).get("location", {}).get("lng"),
                        "rating": row.get("rating"),
                        "review_count": row.get("user_ratings_total", 0),
                        "photo_count": len(row.get("photos") or []),
                        "maps_url": f"https://www.google.com/maps/place/?q=place_id:{row.get('place_id')}",
                    }
                )
                if len(results) >= limit:
                    break

            next_page = payload.get("next_page_token")
            if not next_page:
                break
            await asyncio.sleep(max(REQUEST_DELAY_SECONDS, 2.0))
            params = {"pagetoken": next_page, "key": GOOGLE_PLACES_API_KEY}

    return results[:limit]


async def playwright_demo_scrape(maps_url: str, limit: int) -> list[dict[str, Any]]:
    try:
        from playwright.async_api import async_playwright
    except Exception:
        return []

    items: list[dict[str, Any]] = []
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        page = await browser.new_page()
        await page.goto(maps_url, wait_until="domcontentloaded", timeout=60000)
        await asyncio.sleep(REQUEST_DELAY_SECONDS)
        cards = await page.locator('div[role="article"]').all()
        for card in cards[:limit]:
            try:
                name = (await card.inner_text()).split("\n")[0].strip()
                if name:
                    items.append({"name": name, "category": "Kafe", "city": "İstanbul"})
            except Exception:
                continue
            await asyncio.sleep(REQUEST_DELAY_SECONDS)
        await browser.close()
    return items


@app.get("/health")
async def health() -> dict[str, str]:
    return {
        "status": "ok",
        "provider": "places_api" if GOOGLE_PLACES_API_KEY else "none",
    }


@app.post("/crawl")
async def crawl(body: CrawlRequest) -> dict[str, Any]:
    query = body.query or extract_query(body.maps_url)
    businesses = await places_text_search(query, body.limit)

    if not businesses and os.getenv("CRAWLER_ALLOW_PLAYWRIGHT", "false").lower() == "true":
        businesses = await playwright_demo_scrape(body.maps_url, body.limit)

    return {
        "query": query,
        "count": len(businesses),
        "businesses": businesses,
        "note": "Places API oncelikli; Playwright opsiyonel.",
    }
