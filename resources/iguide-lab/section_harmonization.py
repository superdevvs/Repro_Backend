"""Bounded, opt-in Dirichlet gradient harmonization; no I/O or network.

Input RGB is encoded uint8, not scene radiance. For Omega={support>0}, solve
    4 h[p] - sum(h[q], q inside Omega) = sum(source[q]-candidate[q], q outside).
The four-neighbor correction h makes u=candidate+h follow candidate gradients
with fixed source exterior. Source values inside Omega never enter the solve.

The returned RGB is UNASSOCIATED: a caller must composite with the unchanged
gray support exactly once. Soft support values determine domain membership,
not equation weights. Holes remain fixed source boundaries. This function
does not expand, feather, resize, or infer the mask and never accepts an image.

The unknown cap bounds the problem before factorization; the factor-storage
guard runs after allocation. The invoking process ALSO requires an external
memory/time limit. Recorded sparse bytes exclude allocator/library overhead.
"""
from __future__ import annotations

import hashlib
import time

import numpy as np
import scipy
from scipy import ndimage, sparse
from scipy.sparse.linalg import splu

ALGORITHM_VERSION = "exact-domain-harmonic/1"
EXPECTED_SHAPE = (2048, 2048)
MAX_UNKNOWN_PIXELS = 131_072
MAX_FACTOR_STORAGE_BYTES = 128 * 1024 * 1024
MAX_RESIDUAL = 1e-7
NUMERICAL_TOLERANCE = 1e-7
# Experimental operational limits, NOT calibrated measures of visual quality.
MAX_CLIPPED_PIXEL_FRACTION = 0.05
MAX_OVERSHOOT = 64.0


class HarmonizationError(ValueError):
    """Fail-closed rejection. The caller retains the original input assets."""

    def __init__(self, code: str, message: str, telemetry: dict, clipping_mask=None):
        super().__init__(message)
        self.code = code
        self.telemetry = dict(telemetry)
        self.clipping_mask = clipping_mask


def _pixel_hash(array: np.ndarray) -> str:
    return hashlib.sha256(memoryview(np.ascontiguousarray(array))).hexdigest()


def _sparse_bytes(matrix) -> int:
    return int(matrix.data.nbytes + matrix.indices.nbytes + matrix.indptr.nbytes)


def harmonize(source_rgb: np.ndarray, candidate_rgb: np.ndarray, support_l: np.ndarray):
    """Public wrapper also reports allocation failures outside factorization."""
    try:
        return _harmonize(source_rgb, candidate_rgb, support_l)
    except MemoryError as error:
        raise HarmonizationError("memory_failure", "Harmonization allocation failed", {
            "algorithmVersion": ALGORITHM_VERSION, "status": "rejected",
            "failureCode": "memory_failure", "automaticAcceptance": False,
            "requiresVisualReview": True,
        }) from error


def _harmonize(source_rgb: np.ndarray, candidate_rgb: np.ndarray, support_l: np.ndarray):
    """Return (unassociated RGB, JSON telemetry, L clipping map), or reject.

    All arrays must be uint8, with exact 2048-square dimensions. Inputs remain
    unmodified, including any fractional support. A positive support pixel at
    an image edge, an empty support, or more than 65,536 unknowns is rejected.
    The section adapter independently enforces its wider projection guard.
    """
    started = time.monotonic()
    report = {
        "algorithmVersion": ALGORITHM_VERSION,
        "status": "unreviewed",
        "requiresVisualReview": True,
        "automaticAcceptance": False,
        "numpyVersion": np.__version__,
        "scipyVersion": scipy.__version__,
        "colorSpace": "encoded RGB uint8; float64 solve, not scene radiance",
        "alphaApplication": "caller applies original gray support exactly once",
        "sourceInteriorUsed": False,
        "supportExpanded": False,
        "limits": {
            "unknownPixels": MAX_UNKNOWN_PIXELS,
            "factorStorageBytes": MAX_FACTOR_STORAGE_BYTES,
            "maxResidual": MAX_RESIDUAL,
            "clippedPixelFraction": MAX_CLIPPED_PIXEL_FRACTION,
            "maxOvershoot": MAX_OVERSHOOT,
            "clippingLimitKind": "experimental operational bound; not visual acceptance",
            "numericalTolerance": NUMERICAL_TOLERANCE,
        },
    }

    def reject(code, message, clipping_mask=None):
        report.update(status="rejected", failureCode=code,
                      elapsedMs=(time.monotonic() - started) * 1000)
        raise HarmonizationError(code, message, report, clipping_mask)

    for name, array, shape in [("source", source_rgb, (*EXPECTED_SHAPE, 3)),
                               ("candidate", candidate_rgb, (*EXPECTED_SHAPE, 3)),
                               ("support", support_l, EXPECTED_SHAPE)]:
        if not isinstance(array, np.ndarray) or array.dtype != np.uint8 or array.shape != shape:
            reject("invalid_input", f"{name} requires exact {shape} uint8 array")

    count = int(np.count_nonzero(support_l))
    report["editablePixels"] = count
    if count == 0:
        reject("empty_domain", "Support is empty")
    if count > MAX_UNKNOWN_PIXELS:
        reject("domain_limit", "Editable domain exceeds the initial 65,536-pixel limit")
    if any(np.any(edge) for edge in [support_l[0], support_l[-1], support_l[:, 0], support_l[:, -1]]):
        reject("edge_domain", "Support requires a source boundary on all four sides")

    report["pixelSha256"] = {"source": _pixel_hash(source_rgb),
                              "candidate": _pixel_hash(candidate_rgb),
                              "support": _pixel_hash(support_l)}
    ys, xs = np.nonzero(support_l)
    y0, y1 = int(ys.min()) - 1, int(ys.max()) + 2
    x0, x1 = int(xs.min()) - 1, int(xs.max()) + 2
    report["domainBoundsExclusive"] = [x0 + 1, y0 + 1, x1 - 1, y1 - 1]
    report["workingBoundsExclusive"] = [x0, y0, x1, y1]
    local_domain = support_l[y0:y1, x0:x1] > 0
    _, components = ndimage.label(local_domain, structure=ndimage.generate_binary_structure(2, 1))
    report["components4Connected"] = int(components)
    ids = np.full(local_domain.shape, -1, np.int32)
    ids[ys - y0, xs - x0] = np.arange(count, dtype=np.int32)
    index = np.arange(count, dtype=np.int32)
    rows, columns, values = [index], [index], [np.full(count, 4.0)]
    rhs = np.zeros((count, 3), dtype=np.float64)
    boundary_min = np.full(3, np.inf)
    boundary_max = np.full(3, -np.inf)
    boundary_edges = 0
    for dy, dx in [(0, 1), (0, -1), (1, 0), (-1, 0)]:
        ny, nx = ys + dy, xs + dx
        neighbors = ids[ny - y0, nx - x0]
        inside = neighbors >= 0
        rows.append(index[inside])
        columns.append(neighbors[inside])
        values.append(np.full(int(inside.sum()), -1.0))
        outside = ~inside
        if np.any(outside):
            delta = (source_rgb[ny[outside], nx[outside]].astype(np.float64)
                     - candidate_rgb[ny[outside], nx[outside]].astype(np.float64))
            rhs[outside] += delta
            boundary_min = np.minimum(boundary_min, delta.min(axis=0))
            boundary_max = np.maximum(boundary_max, delta.max(axis=0))
            boundary_edges += int(outside.sum())
    matrix = sparse.coo_matrix((np.concatenate(values), (np.concatenate(rows), np.concatenate(columns))),
                               shape=(count, count)).tocsc()
    report.update(matrixNnz=int(matrix.nnz), matrixBytes=_sparse_bytes(matrix),
                  boundaryEdges=boundary_edges,
                  boundaryCorrectionMinimum=boundary_min.tolist(),
                  boundaryCorrectionMaximum=boundary_max.tolist(),
                  solver="SuperLU direct; MMD_AT_PLUS_A; symmetric mode; three RHS",
                  iterations=None)
    del rows, columns, values, ids, local_domain
    try:
        factor = splu(matrix, permc_spec="MMD_AT_PLUS_A", diag_pivot_thresh=0.0,
                      options={"SymmetricMode": True})
        lower, upper = factor.L, factor.U
        factor_bytes = _sparse_bytes(lower) + _sparse_bytes(upper)
        report.update(factorBytes=factor_bytes, factorNnz=int(lower.nnz + upper.nnz),
                      memoryAccounting="materialized sparse storage only; external process memory limit required")
        if factor_bytes > MAX_FACTOR_STORAGE_BYTES:
            reject("factor_memory_limit", "Sparse factor exceeds the operational storage limit")
        del lower, upper
        correction = factor.solve(rhs)
    except HarmonizationError:
        raise
    except MemoryError:
        reject("memory_failure", "Sparse factorization or solve ran out of memory")
    except (RuntimeError, ValueError) as error:
        reject("solver_failure", f"Sparse factorization or solve failed: {error}")

    if correction.shape != rhs.shape or not np.all(np.isfinite(correction)):
        reject("nonfinite_solution", "Solver returned an invalid or nonfinite correction")
    residual = float(np.max(np.abs(matrix @ correction - rhs)))
    report["maxResidual"] = residual
    if not np.isfinite(residual) or residual > MAX_RESIDUAL:
        reject("residual_limit", "Solution residual exceeds the numerical limit")
    if (np.any(correction < boundary_min - NUMERICAL_TOLERANCE)
            or np.any(correction > boundary_max + NUMERICAL_TOLERANCE)):
        reject("correction_bounds", "Correction violates the harmonic maximum principle")

    unclipped = candidate_rgb[ys, xs].astype(np.float64) + correction
    if not np.all(np.isfinite(unclipped)):
        reject("nonfinite_output", "Harmonized channels are nonfinite")
    below = unclipped < -NUMERICAL_TOLERANCE
    above = unclipped > 255.0 + NUMERICAL_TOLERANCE
    clipped_channels = below | above
    clipped_pixels = np.any(clipped_channels, axis=1)
    clipped_count = int(clipped_pixels.sum())
    max_overshoot = float(max(0.0, -unclipped.min(), unclipped.max() - 255.0))
    clip_y, clip_x = ys[clipped_pixels], xs[clipped_pixels]
    report.update(clippedPixels=clipped_count, clippedChannels=int(clipped_channels.sum()),
                  clippedPixelFraction=clipped_count / count,
                  clippedChannelFraction=int(clipped_channels.sum()) / (3 * count),
                  belowZeroChannels=int(below.sum()), above255Channels=int(above.sum()),
                  maxOvershoot=max_overshoot, preclipMinimum=float(unclipped.min()),
                  preclipMaximum=float(unclipped.max()),
                  clippingBoundsExclusive=([int(clip_x.min()), int(clip_y.min()), int(clip_x.max()) + 1,
                                            int(clip_y.max()) + 1] if clipped_count else None))
    clipping_mask = np.zeros(EXPECTED_SHAPE, np.uint8)
    clipping_mask[clip_y, clip_x] = 255
    report["clippingMaskPixelSha256"] = _pixel_hash(clipping_mask)
    if clipped_count * 20 > count:
        reject("clipping_fraction_limit", "More than 5% of editable pixels require channel clipping", clipping_mask)
    if max_overshoot > MAX_OVERSHOOT + NUMERICAL_TOLERANCE:
        reject("clipping_overshoot_limit", "Channel overshoot exceeds the experimental 64-unit bound", clipping_mask)

    result = source_rgb.copy()
    result[ys, xs] = np.rint(np.clip(unclipped, 0, 255)).astype(np.uint8)
    report.update(rounding="numpy.rint after clipping to [0,255]",
                  outsideSupportChangedPixels=0,
                  outputPixelSha256=_pixel_hash(result))
    report["elapsedMs"] = (time.monotonic() - started) * 1000
    return result, report, clipping_mask
