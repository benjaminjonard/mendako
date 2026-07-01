import numpy as np

from app import inference


class _FakeTextInput:
    name = "input_ids"
    shape = [1, 64]
    type = "tensor(int64)"


class _FakeTextSession:
    """Returns one one-hot vector per row, keyed on the row's first token id."""

    def get_inputs(self):
        return [_FakeTextInput()]

    def run(self, _outputs, feed):
        rows = np.asarray(feed["input_ids"])  # [N, seq_len]
        out = np.zeros((rows.shape[0], 1152), dtype=np.float32)
        for i in range(rows.shape[0]):
            out[i, int(rows[i][0]) % 1152] = 1.0
        return [out]


class _FakeTokenizer:
    def encode(self, text):
        return type("E", (), {"ids": [ord(text[0]), 0, 0]})()


def _setup(monkeypatch, tmp_path):
    # Point the persistent store at a throwaway dir and drop any in-memory state.
    monkeypatch.setenv("MENDAKO_TEXT_CACHE_DIR", str(tmp_path / "text-cache"))
    inference._text_store.clear()
    inference._warm_state.update(running=False, done=0, total=0)
    inference._tokenizer.cache_clear()
    inference._session.cache_clear()
    inference._text_session.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeTextSession())
    monkeypatch.setattr(inference, "_text_session", lambda _p: _FakeTextSession())
    monkeypatch.setattr(inference, "_tokenizer", lambda _p: _FakeTokenizer())


def test_embed_texts_returns_normalized_vectors(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)

    out = inference.embed_texts(tmp_path, ["cat", "dog"])

    assert len(out) == 2
    for vector in out:
        assert len(vector) == 1152
        assert abs(float(np.linalg.norm(np.array(vector, dtype=np.float32))) - 1.0) < 1e-5


def test_zeroshot_scores_against_text_and_sorts(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)
    inference.warm_vocabulary(tmp_path, ["cat", "dog"])  # populate the cache first
    # Image embedding aligned with the 'cat' text vector (one-hot at ord('c')).
    image = [0.0] * 1152
    image[ord("c") % 1152] = 1.0

    results = inference.zeroshot(tmp_path, image, ["cat", "dog"], 10, 0.0)

    assert results[0]["name"] == "cat"
    assert abs(results[0]["score"] - 1.0) < 1e-5
    assert results[0]["score"] > results[1]["score"]  # cat (aligned) outranks dog (orthogonal)


def test_zeroshot_uses_only_cached_vectors(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)
    # No warm-up → nothing cached → zero-shot returns nothing (never encodes on the image path).
    results = inference.zeroshot(tmp_path, [0.0] * 1152, ["cat", "dog"], 10, 0.0)
    assert results == []


def test_zeroshot_applies_floor_and_top_n(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)
    inference.warm_vocabulary(tmp_path, ["cat", "dog"])
    image = [0.0] * 1152
    image[ord("c") % 1152] = 1.0

    # min_score 0.5 drops the orthogonal 'dog' (score 0); top_n 1 caps the list.
    results = inference.zeroshot(tmp_path, image, ["cat", "dog"], 1, 0.5)

    assert len(results) == 1
    assert results[0]["name"] == "cat"


def test_warm_vocabulary_tracks_progress_and_persists(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)

    inference.warm_vocabulary(tmp_path, ["cat", "dog", "fox"])

    status = inference.warm_status()
    assert status == {"running": False, "done": 3, "total": 3}
    assert inference._text_cache_path(str(tmp_path)).is_file()


def test_vocabulary_coverage_counts_cached_and_missing(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)
    inference.warm_vocabulary(tmp_path, ["cat", "dog"])

    cached, missing = inference.vocabulary_coverage(tmp_path, ["cat", "dog", "fox", "owl"])
    assert (cached, missing) == (2, 2)
    # Read-only: coverage must not encode anything (would raise here if it did).
    monkeypatch.setattr(inference, "_text_session", lambda _p: (_ for _ in ()).throw(AssertionError("encoded")))
    assert inference.vocabulary_coverage(tmp_path, ["cat", "new"]) == (1, 1)


def test_warm_vocabulary_is_incremental_and_reuses_cache(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)
    inference.warm_vocabulary(tmp_path, ["cat", "dog"])

    # A fresh in-memory store loads from disk; re-warming must not re-encode cached tags.
    inference._text_store.clear()
    monkeypatch.setattr(inference, "_text_session", lambda _p: (_ for _ in ()).throw(AssertionError("re-encoded")))
    inference.warm_vocabulary(tmp_path, ["cat", "dog"])  # all cached → no encoding
    assert inference.warm_status()["done"] == 2


def test_warm_vocabulary_reset_re_encodes_everything(monkeypatch, tmp_path):
    _setup(monkeypatch, tmp_path)
    inference.warm_vocabulary(tmp_path, ["cat", "dog"])

    calls = {"n": 0}
    real_encode = inference._encode_one
    monkeypatch.setattr(inference, "_encode_one", lambda md, t: (calls.__setitem__("n", calls["n"] + 1), real_encode(md, t))[1])
    inference.warm_vocabulary(tmp_path, ["cat", "dog"], reset=True)

    assert calls["n"] == 2  # cache wiped → both re-encoded despite having been cached
    assert inference.warm_status() == {"running": False, "done": 2, "total": 2}
