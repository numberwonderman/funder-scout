from __future__ import annotations

import ipaddress
import re
import socket
import os
import xml.etree.ElementTree as ET
import json
from datetime import datetime, timezone
from html import unescape
from urllib.parse import urlencode, urljoin, urlparse

import httpx
from strands import tool

USER_AGENT = "FunderScout/1.0 public-interest research; contact=admin@localhost"


def _safe_public_url(url: str) -> str:
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise ValueError("Only public HTTP(S) URLs are allowed")
    for address in socket.getaddrinfo(parsed.hostname, parsed.port or 443, type=socket.SOCK_STREAM):
        ip = ipaddress.ip_address(address[4][0])
        if not ip.is_global:
            raise ValueError("Private, loopback, and link-local addresses are blocked")
    return url


def _public_get(client: httpx.Client, url: str, max_redirects: int = 3) -> httpx.Response:
    """GET an untrusted URL without allowing redirects to bypass SSRF checks."""
    current = _safe_public_url(url)
    for _ in range(max_redirects + 1):
        response = client.get(current, follow_redirects=False)
        if response.status_code not in {301, 302, 303, 307, 308}:
            return response
        location = response.headers.get("location")
        if not location:
            return response
        current = _safe_public_url(urljoin(current, location))
    raise ValueError("Too many redirects")


def _text(html: str, limit: int = 20000) -> str:
    html = re.sub(r"<(script|style|noscript)[^>]*>.*?</\1>", " ", html, flags=re.I | re.S)
    return re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", " ", html)))[:limit].strip()


def _links(html: str, base_url: str, limit: int = 80) -> list[dict[str, str]]:
    found: list[dict[str, str]] = []
    seen: set[str] = set()
    for href, label in re.findall(r'<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>', html, flags=re.I | re.S):
        url = urljoin(base_url, unescape(href)).split("#", 1)[0]
        if url in seen or urlparse(url).scheme not in {"http", "https"}:
            continue
        seen.add(url)
        found.append({"url": url, "label": _text(label, 180)})
        if len(found) >= limit:
            break
    return found


@tool
def search_public_web(query: str) -> dict:
    """Search the public web for grant, funder, partner, and leadership evidence.

    Uses Brave Search when BRAVE_SEARCH_API_KEY is configured, otherwise uses
    Bing's public RSS search response. Search results are discovery leads only;
    fetch the result page before treating it as evidence.
    """
    clean_query = re.sub(r"\s+", " ", query).strip()[:300]
    if len(clean_query) < 3:
        raise ValueError("Search query is too short")
    brave_key = os.getenv("BRAVE_SEARCH_API_KEY")
    with httpx.Client(timeout=6, follow_redirects=True, headers={"User-Agent": USER_AGENT}) as client:
        if brave_key:
            url = "https://api.search.brave.com/res/v1/web/search"
            response = client.get(url, params={"q": clean_query, "count": 8}, headers={"X-Subscription-Token": brave_key, "Accept": "application/json"})
            response.raise_for_status()
            rows = response.json().get("web", {}).get("results", [])[:8]
            results = [{"title": row.get("title", ""), "url": row.get("url", ""), "snippet": row.get("description", "")} for row in rows]
            provider = "brave"
        else:
            url = "https://www.bing.com/search"
            response = client.get(url, params={"q": clean_query, "format": "rss"})
            response.raise_for_status()
            root = ET.fromstring(response.text)
            results = []
            for item in root.findall("./channel/item")[:8]:
                result_url = (item.findtext("link") or "").strip()
                if not result_url:
                    continue
                results.append({"title": (item.findtext("title") or "").strip(), "url": result_url, "snippet": _text(item.findtext("description") or "", 500)})
            provider = "bing_rss"
    return {"provider": provider, "query": clean_query, "results": results, "notice": "Discovery leads only. Fetch a result page before citing it as evidence."}


@tool
def fetch_public_page(url: str) -> dict:
    """Fetch a legitimate public webpage and return source-preserving readable text."""
    safe_url = _safe_public_url(url)
    with httpx.Client(timeout=6, follow_redirects=False, headers={"User-Agent": USER_AGENT}) as client:
        response = _public_get(client, safe_url)
        response.raise_for_status()
        final_url = _safe_public_url(str(response.url))
        return {"title": final_url, "url": final_url, "source_type": "public_web", "text": _text(response.text), "links": _links(response.text, final_url), "retrieved_from_status": response.status_code}


@tool
def crawl_public_site(url: str, focus: str = "funders grants partners supporters annual reports leadership team") -> dict:
    """Crawl a bounded set of high-value public pages and preserve source URLs and outgoing links.

    Use this for nonprofit, funder, grant, partner, annual-report, leadership,
    staff, and contact research. It never signs in or bypasses access controls.
    """
    start_url = _safe_public_url(url)
    start_host = urlparse(start_url).hostname
    terms = {term.lower() for term in re.findall(r"[a-zA-Z]{3,}", focus)}
    terms.update({"fund", "grant", "partner", "support", "donor", "annual", "report", "team", "staff", "leadership", "contact", "water", "wash"})
    pages: list[dict] = []
    candidates: dict[str, int] = {start_url: 100}

    started_at = datetime.now(timezone.utc).timestamp()
    with httpx.Client(timeout=3, follow_redirects=False, headers={"User-Agent": USER_AGENT}) as client:
        try:
            sitemap_url = f"{urlparse(start_url).scheme}://{start_host}/sitemap.xml"
            sitemap = _public_get(client, sitemap_url)
            if sitemap.is_success:
                for candidate in re.findall(r"<loc>(.*?)</loc>", sitemap.text, flags=re.I):
                    candidate = unescape(candidate.strip())
                    score = sum(term in candidate.lower() for term in terms)
                    if score:
                        candidates[candidate] = score
        except (httpx.HTTPError, ValueError):
            pass

        visited: set[str] = set()
        while candidates and len(pages) < 4 and datetime.now(timezone.utc).timestamp() - started_at < 7:
            current = max(candidates, key=candidates.get)
            candidates.pop(current, None)
            if current in visited or urlparse(current).hostname != start_host:
                continue
            visited.add(current)
            try:
                safe = _safe_public_url(current)
                response = _public_get(client, safe)
                response.raise_for_status()
                content_type = response.headers.get("content-type", "")
                if "html" not in content_type:
                    continue
                final_url = _safe_public_url(str(response.url))
                links = _links(response.text, final_url)
                pages.append({"url": final_url, "source_type": "public_site_crawl", "text": _text(response.text, 7000), "links": links[:30]})
                for link in links:
                    linked_url = link["url"]
                    haystack = f'{linked_url} {link["label"]}'.lower()
                    score = sum(term in haystack for term in terms)
                    if score and urlparse(linked_url).hostname == start_host and linked_url not in visited:
                        candidates[linked_url] = max(candidates.get(linked_url, 0), score)
            except (httpx.HTTPError, ValueError):
                continue

    external_links = []
    seen_external = set()
    for page in pages:
        for link in page["links"]:
            host = urlparse(link["url"]).hostname
            if host and host != start_host and link["url"] not in seen_external:
                seen_external.add(link["url"])
                external_links.append(link)
    return {"start_url": start_url, "source_type": "public_site_crawl", "pages": pages, "relevant_external_links": external_links[:60]}


@tool
def search_nonprofit_explorer(query: str) -> dict:
    """Search ProPublica Nonprofit Explorer for public nonprofit profiles."""
    url = "https://projects.propublica.org/nonprofits/api/v2/search.json?" + urlencode({"q": query})
    with httpx.Client(timeout=6, headers={"User-Agent": USER_AGENT}) as client:
        response = client.get(url)
        response.raise_for_status()
        data = response.json()
    organizations = data.get("organizations", [])[:10]
    return {"source_url": url, "source_type": "nonprofit_explorer", "organizations": [{"name": row.get("name"), "ein": row.get("ein"), "city": row.get("city"), "state": row.get("state"), "ntee_code": row.get("ntee_code")} for row in organizations]}


@tool
def get_nonprofit_profile(ein: str) -> dict:
    """Retrieve a public ProPublica Nonprofit Explorer organization profile by EIN."""
    clean_ein = re.sub(r"\D", "", ein)
    if len(clean_ein) != 9:
        raise ValueError("EIN must contain nine digits")
    url = f"https://projects.propublica.org/nonprofits/api/v2/organizations/{clean_ein}.json"
    with httpx.Client(timeout=6, headers={"User-Agent": USER_AGENT}) as client:
        response = client.get(url)
        response.raise_for_status()
        data = response.json()
    return {"source_url": url, "source_type": "nonprofit_explorer", "organization": data.get("organization"), "filings_with_data": data.get("filings_with_data", [])[:5]}


def _evidence_page(title: str, url: str, source_type: str, records: object) -> dict:
    return {
        "title": title,
        "url": url,
        "source_type": source_type,
        "text": json.dumps(records, ensure_ascii=False, separators=(",", ":"))[:16000],
    }


@tool
def search_irs_filings(query: str) -> dict:
    """Find IRS-backed nonprofit profiles and recent 990/990-PF filing facts."""
    clean_query = re.sub(r"\s+", " ", query).strip()[:240]
    search_url = "https://projects.propublica.org/nonprofits/api/v2/search.json?" + urlencode({"q": clean_query})
    pages = []
    with httpx.Client(timeout=4, headers={"User-Agent": USER_AGENT}) as client:
        response = client.get(search_url)
        response.raise_for_status()
        organizations = response.json().get("organizations", [])[:2]
        for organization in organizations:
            ein = re.sub(r"\D", "", str(organization.get("ein") or ""))
            if len(ein) != 9:
                continue
            try:
                profile_url = f"https://projects.propublica.org/nonprofits/api/v2/organizations/{ein}.json"
                profile_response = client.get(profile_url)
                profile_response.raise_for_status()
                profile = profile_response.json()
            except httpx.HTTPError:
                continue
            pages.append(_evidence_page(
                f"IRS Form 990 profile: {organization.get('name')}",
                f"https://projects.propublica.org/nonprofits/organizations/{ein}",
                "irs_form_990",
                {"organization": profile.get("organization"), "recent_filings": profile.get("filings_with_data", [])[:5]},
            ))
    return {"provider": "irs_propublica", "pages": pages}


@tool
def search_grants_gov(query: str) -> dict:
    """Search currently posted or forecasted US federal grant opportunities."""
    url = "https://api.grants.gov/v1/api/search2"
    keyword = re.sub(r"\s+", " ", query).strip()[:240]
    with httpx.Client(timeout=6, headers={"User-Agent": USER_AGENT}) as client:
        response = client.post(url, json={"rows": 10, "keyword": keyword, "oppStatuses": "forecasted|posted"})
        response.raise_for_status()
        hits = response.json().get("data", {}).get("oppHits", [])[:10]
    pages = [
        _evidence_page(
            f"Grants.gov: {hit.get('title') or hit.get('number')}",
            f"https://www.grants.gov/search-results-detail/{hit.get('id')}",
            "grants_gov",
            hit,
        ) for hit in hits if hit.get("id")
    ]
    return {"provider": "grants_gov", "pages": pages}


@tool
def search_usaspending(query: str) -> dict:
    """Search historical US federal assistance awards by campaign keywords."""
    url = "https://api.usaspending.gov/api/v2/search/spending_by_award/"
    year = datetime.now(timezone.utc).year
    body = {
        "subawards": False,
        "limit": 10,
        "page": 1,
        "filters": {
            "time_period": [{"start_date": f"{year - 5}-01-01", "end_date": f"{year}-12-31"}],
            "award_type_codes": ["02", "03", "04", "05", "06", "10", "11"],
            "keywords": [re.sub(r"\s+", " ", query).strip()[:200]],
        },
        "fields": ["Award ID", "Recipient Name", "Award Amount", "Awarding Agency", "Start Date", "End Date", "Description", "Assistance Listings"],
    }
    with httpx.Client(timeout=6, headers={"User-Agent": USER_AGENT}) as client:
        response = client.post(url, json=body)
        response.raise_for_status()
        rows = response.json().get("results", [])[:10]
    pages = []
    for row in rows:
        award_id = row.get("generated_subaward_id") or row.get("Award ID") or row.get("internal_id")
        if award_id:
            pages.append(_evidence_page(f"USAspending award: {row.get('Recipient Name') or award_id}", f"https://www.usaspending.gov/award/{award_id}", "usaspending_award", row))
    return {"provider": "usaspending", "pages": pages}


@tool
def search_candid_grants(query: str) -> dict:
    """Search Candid grant transactions when a subscription key is configured."""
    key = os.getenv("CANDID_SUBSCRIPTION_KEY", "").strip()
    if not key:
        return {"provider": "candid", "configured": False, "pages": []}
    endpoint = "https://api.candid.org/grants/v1/transactions"
    clean = re.sub(r"\s+", " ", query).strip()[:240]
    with httpx.Client(timeout=6, headers={"User-Agent": USER_AGENT, "Accept": "application/json", "Subscription-Key": key}) as client:
        response = client.get(endpoint, params={"query": clean, "sort_by": "year_issued", "sort_order": "desc", "page": 1, "format": "json"})
        response.raise_for_status()
        data = response.json()
    rows = data if isinstance(data, list) else data.get("results", data.get("data", []))
    if not isinstance(rows, list):
        rows = []
    pages = []
    for index, row in enumerate(rows[:10]):
        pages.append(_evidence_page(f"Candid grant transaction {index + 1}", f"{endpoint}?{urlencode({'query': clean, 'page': 1})}", "candid_grants", row))
    return {"provider": "candid", "configured": True, "pages": pages}
