from app.tools import public_data


class JsonResponse:
    def __init__(self, data):
        self._data = data

    def raise_for_status(self):
        return None

    def json(self):
        return self._data


def test_bing_rss_search_is_bounded_and_marked_as_discovery_only(monkeypatch):
    xml = """<?xml version="1.0"?><rss><channel>
    <item><title>Water fund</title><link>https://example.org/water</link><description>Water grants in Malawi</description></item>
    </channel></rss>"""

    class Response:
        text = xml

        def raise_for_status(self):
            return None

    class Client:
        def __init__(self, *args, **kwargs):
            pass

        def __enter__(self):
            return self

        def __exit__(self, *args):
            return None

        def get(self, *args, **kwargs):
            return Response()

    monkeypatch.delenv("BRAVE_SEARCH_API_KEY", raising=False)
    monkeypatch.setattr(public_data.httpx, "Client", Client)

    result = public_data.search_public_web("water grants Malawi")

    assert result["provider"] == "bing_rss"
    assert result["results"] == [{"title": "Water fund", "url": "https://example.org/water", "snippet": "Water grants in Malawi"}]
    assert "Discovery leads only" in result["notice"]


def test_irs_filings_returns_bounded_evidence_pages(monkeypatch):
    responses = [
        JsonResponse({"organizations": [{"name": "Water Trust", "ein": 123456789}]}),
        JsonResponse({"organization": {"name": "Water Trust"}, "filings_with_data": [{"tax_prd": 2024, "totrevenue": 100000}]}),
    ]

    class Client:
        def __init__(self, *args, **kwargs): pass
        def __enter__(self): return self
        def __exit__(self, *args): return None
        def get(self, *args, **kwargs): return responses.pop(0)

    monkeypatch.setattr(public_data.httpx, "Client", Client)

    result = public_data.search_irs_filings("Water Trust")

    assert result["provider"] == "irs_propublica"
    assert result["pages"][0]["source_type"] == "irs_form_990"
    assert '"totrevenue":100000' in result["pages"][0]["text"]


def test_grants_gov_returns_citable_opportunity(monkeypatch):
    class Client:
        def __init__(self, *args, **kwargs): pass
        def __enter__(self): return self
        def __exit__(self, *args): return None
        def post(self, *args, **kwargs): return JsonResponse({"data": {"oppHits": [{"id": "123", "title": "Clean Water"}]}})

    monkeypatch.setattr(public_data.httpx, "Client", Client)

    result = public_data.search_grants_gov("clean water Malawi")

    assert result["pages"][0]["url"] == "https://www.grants.gov/search-results-detail/123"
    assert result["pages"][0]["source_type"] == "grants_gov"


def test_usaspending_returns_citable_award(monkeypatch):
    class Client:
        def __init__(self, *args, **kwargs): pass
        def __enter__(self): return self
        def __exit__(self, *args): return None
        def post(self, *args, **kwargs): return JsonResponse({"results": [{"Award ID": "ASST-1", "Recipient Name": "Water Partner", "Award Amount": 50000}]})

    monkeypatch.setattr(public_data.httpx, "Client", Client)

    result = public_data.search_usaspending("clean water Malawi")

    assert result["pages"][0]["url"] == "https://www.usaspending.gov/award/ASST-1"
    assert '"Award Amount":50000' in result["pages"][0]["text"]


def test_candid_skips_network_without_subscription_key(monkeypatch):
    monkeypatch.delenv("CANDID_SUBSCRIPTION_KEY", raising=False)

    result = public_data.search_candid_grants("clean water Malawi")

    assert result == {"provider": "candid", "configured": False, "pages": []}


def test_candid_uses_subscription_key_and_returns_grants(monkeypatch):
    observed_headers = {}

    class Client:
        def __init__(self, *args, **kwargs): observed_headers.update(kwargs["headers"])
        def __enter__(self): return self
        def __exit__(self, *args): return None
        def get(self, *args, **kwargs): return JsonResponse({"results": [{"funder_name": "Water Fund", "amount": 25000}]})

    monkeypatch.setenv("CANDID_SUBSCRIPTION_KEY", "test-key")
    monkeypatch.setattr(public_data.httpx, "Client", Client)

    result = public_data.search_candid_grants("clean water Malawi")

    assert observed_headers["Subscription-Key"] == "test-key"
    assert result["configured"] is True
    assert result["pages"][0]["source_type"] == "candid_grants"
