"""Deterministic panorama cuts/reprojection. No AI calls or source mutation.

Camera convention: raw panorama longitude zero is its center, positive yaw
looks right, positive pitch looks up. It is NOT calibrated iGUIDE world yaw.
Pixel centers, not pixel edges, define the sampling coordinates throughout.
"""
from __future__ import annotations

import argparse
import hashlib
import itertools
import json
import math
import re
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw

VERSION = 1
PIPELINE_VERSION = "iguide-local-geometry/1.3.0"
STRIP = 128
CONVENTION = "pixel centers; raw yaw=0 at equirectangular u=0.5; yaw right; pitch up; wrap longitude"
FACES = [("front", 0, 0), ("right", 90, 0), ("back", 180, 0),
         ("left", 270, 0), ("zenith", 0, 90), ("nadir", 0, -90)]


def sha256(path):
    h = hashlib.sha256()
    with Path(path).open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def write_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("x", encoding="utf-8") as f:
        json.dump(value, f, indent=2, allow_nan=False)


def save_new(image, path):
    path = Path(path)
    if path.exists():
        raise ValueError(f"Refusing to overwrite existing file: {path}")
    if path.suffix.lower() != ".png":
        raise ValueError("Derived images must be PNG to avoid another lossy JPEG pass")
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, format="PNG")


def rgb(path):
    with Image.open(path) as im:
        if getattr(im, "n_frames", 1) != 1:
            raise ValueError("Animated input is not supported")
        return np.asarray(im.convert("RGB"))


def sphere_size(a):
    h, w = a.shape[:2]
    if w != 2 * h:
        raise ValueError(f"Expected exact 2:1 equirectangular image, got {w}x{h}")
    return w, h


def bilinear(a, u, v, wrap_x=False):
    h, w = a.shape[:2]
    u = u % w if wrap_x else np.clip(u, 0, w - 1)
    v = np.clip(v, 0, h - 1)
    x0, y0 = np.floor(u).astype(np.int32), np.floor(v).astype(np.int32)
    dx, dy = (u - x0).astype(np.float32), (v - y0).astype(np.float32)
    # float32 modulo can round a tiny negative coordinate to exactly w.
    # Keep its fractional part, then wrap the integer index as well.
    if wrap_x:
        x0 %= w
    x1 = (x0 + 1) % w if wrap_x else np.minimum(x0 + 1, w - 1)
    y1 = np.minimum(y0 + 1, h - 1)
    if a.ndim == 3:
        dx, dy = dx[..., None], dy[..., None]
    return (a[y0, x0] * ((1-dx)*(1-dy)) + a[y0, x1] * (dx*(1-dy))
            + a[y1, x0] * ((1-dx)*dy) + a[y1, x1] * (dx*dy)).astype(np.float32)


def mipmaps(a):
    """Prefilter before minification; bilinear alone aliases architectural edges."""
    levels = [a]
    while min(levels[-1].shape[:2]) > 32:
        previous = levels[-1]
        size = (max(1, previous.shape[1]//2), max(1, previous.shape[0]//2))
        levels.append(np.asarray(Image.fromarray(previous).resize(size, Image.Resampling.LANCZOS)))
    return levels


def coordinate_derivative(a, axis, period=None):
    if a.shape[axis] == 1:
        return np.zeros_like(a)
    # One-sided boundaries, centered interior. A wrapped coordinate must be unwrapped locally.
    first = np.take(a, range(1, a.shape[axis]), axis=axis)-np.take(a, range(a.shape[axis]-1), axis=axis)
    if period:
        first = (first+period/2) % period-period/2
    result = np.empty_like(a)
    if axis == 0:
        result[0], result[-1] = first[0], first[-1]
        result[1:-1] = (first[:-1]+first[1:])/2
    else:
        result[:, 0], result[:, -1] = first[:, 0], first[:, -1]
        result[:, 1:-1] = (first[:, :-1]+first[:, 1:])/2
    return result


def sample_antialiased(levels, u, v, wrap_x=False, valid=None):
    """Trilinear mip sampling, using the larger projected pixel footprint.

    This conservative isotropic filter reduces aliasing; it is not an exact
    anisotropic reconstruction filter and can soften fine detail near poles.
    """
    h, w = levels[0].shape[:2]
    period = w if wrap_x else None
    ux, uy = coordinate_derivative(u, 1, period), coordinate_derivative(u, 0, period)
    vx, vy = coordinate_derivative(v, 1), coordinate_derivative(v, 0)
    footprint = np.maximum(np.hypot(ux, vx), np.hypot(uy, vy))
    lod = np.clip(np.log2(np.maximum(footprint, 1)), 0, len(levels)-1)
    low = np.floor(lod).astype(np.int16)
    high = np.minimum(low+1, len(levels)-1)
    fraction = lod-low
    channels = (3,) if levels[0].ndim == 3 else ()
    result = np.zeros((*u.shape, *channels), np.float32)
    for indices, weights in [(low, 1-fraction), (high, fraction)]:
        for index in np.unique(indices):
            selected = (indices == index) & (weights > 0)
            if valid is not None:
                selected &= valid
            if not selected.any():
                continue
            level = levels[int(index)]
            uu = (u[selected]+.5)*level.shape[1]/w-.5
            vv = (v[selected]+.5)*level.shape[0]/h-.5
            weight = weights[selected][..., None] if channels else weights[selected]
            result[selected] += bilinear(level, uu, vv, wrap_x)*weight
    return result


def view_rays(width, height, y0, y1, yaw, pitch, hfov):
    t = np.float32(math.tan(math.radians(hfov) / 2))
    xx, yy = np.meshgrid((2*(np.arange(width, dtype=np.float32)+.5)/width-1)*t,
                         (1-2*(np.arange(y0, y1, dtype=np.float32)+.5)/height)*t*height/width)
    p, q = math.radians(pitch), math.radians(yaw)
    y = yy * math.cos(p) + math.sin(p)
    z = -yy * math.sin(p) + math.cos(p)
    x = xx * math.cos(q) + z * math.sin(q)
    z2 = -xx * math.sin(q) + z * math.cos(q)
    return x, y, z2


def perspective(a, yaw, pitch, hfov, size, levels=None):
    iw, ih = sphere_size(a)
    ow, oh = size
    levels = mipmaps(a) if levels is None else levels
    out = np.empty((oh, ow, 3), dtype=np.uint8)
    for y0 in range(0, oh, STRIP):
        y1 = min(oh, y0 + STRIP)
        x, y, z = view_rays(ow, oh, y0, y1, yaw, pitch, hfov)
        lon, lat = np.arctan2(x, z), np.arctan2(y, np.sqrt(x*x+z*z))
        u, v = (lon / (2*np.pi)+.5)*iw-.5, (.5-lat/np.pi)*ih-.5
        out[y0:y1] = np.rint(np.clip(sample_antialiased(levels, u, v, True), 0, 255)).astype(np.uint8)
    return out


def extract(source, destination, size=2048, hfov=100.0, yaw_offset=0.0, provenance=None, view_specs=None):
    source, destination = Path(source).resolve(), Path(destination).resolve()
    if not math.isfinite(yaw_offset):
        raise ValueError("Yaw offset must be finite")
    custom = view_specs is not None
    if not custom:
        if not 90 < hfov <= 120:
            raise ValueError("Six-view coverage needs HFOV > 90 and <= 120 degrees")
        view_specs = [{"id": name, "yaw_degrees": (yaw+yaw_offset)%360, "pitch_degrees": pitch,
                       "hfov_degrees": hfov, "width": size, "height": size} for name, yaw, pitch in FACES]
    if not view_specs or len(view_specs) > 24:
        raise ValueError("Provide between one and 24 view specifications")
    ids = set()
    for spec in view_specs:
        name = spec["id"]
        if not re.fullmatch(r"[a-z0-9_-]{1,64}", name) or name in ids:
            raise ValueError("View IDs must be unique safe lowercase names")
        ids.add(name)
        if any(not isinstance(spec[k], int) or not 64 <= spec[k] <= 4096 or spec[k] % 16 for k in ["width", "height"]):
            raise ValueError("View dimensions must be multiples of 16 between 64 and 4096")
        if (not all(math.isfinite(float(spec[k])) for k in ["yaw_degrees", "pitch_degrees", "hfov_degrees"])
                or not -90 <= spec["pitch_degrees"] <= 90 or not 0 < spec["hfov_degrees"] <= 150):
            raise ValueError("Invalid custom view yaw, pitch or field of view")
    if (destination / "manifest.json").exists():
        raise ValueError("Choose a new extraction directory; manifest already exists")
    original_hash = sha256(source)
    a = rgb(source)
    w, h = sphere_size(a)
    levels = mipmaps(a)
    manifest = {
        "schema_version": VERSION,
        "pipeline_version": PIPELINE_VERSION,
        "sampling": "Footprint-aware trilinear mipmaps with Lanczos level prefilter; conservative isotropic antialiasing",
        "source": {"path": str(source), "sha256": original_hash, "width": w, "height": h,
                   "projection": "equirectangular", "immutable": True},
        "convention": CONVENTION,
        "native_detail_warning": "Export canvas size does not restore detail lost to view sampling or AI output resolution.",
        "layout": {"kind": "custom_views" if custom else "six_overlapping_cube_directions",
                   "full_sphere_coverage_expected": not custom,
                   "hfov_degrees": None if custom else hfov,
                   "nominal_cardinal_overlap_degrees": None if custom else hfov-90,
                   "yaw_offset_degrees": None if custom else yaw_offset},
        "provenance": provenance or {},
        "views": [],
    }
    for spec in view_specs:
        name, yaw, pitch = spec["id"], spec["yaw_degrees"], spec["pitch_degrees"]
        view_fov, ow, oh = spec["hfov_degrees"], spec["width"], spec["height"]
        view = perspective(a, yaw, pitch, view_fov, (ow, oh), levels)
        path = destination / "views" / f"{name}.png"
        save_new(Image.fromarray(view), path)
        manifest["views"].append({
            "id": name, "path": str(path), "sha256": sha256(path),
            "projection": "rectilinear", "width": ow, "height": oh,
            "yaw_degrees": yaw, "pitch_degrees": pitch,
            "hfov_degrees": view_fov,
            "vfov_degrees": math.degrees(2*math.atan(math.tan(math.radians(view_fov)/2)*oh/ow)),
            "center_pixels_per_degree": ow/(2*math.tan(math.radians(view_fov)/2))*math.pi/180,
        })
    if sha256(source) != original_hash:
        raise ValueError("Original changed during extraction")
    write_json(destination / "manifest.json", manifest)
    return manifest


def read_manifest(path):
    m = json.loads(Path(path).read_text(encoding="utf-8"))
    if m.get("schema_version") != VERSION or m.get("convention") != CONVENTION:
        raise ValueError("Unsupported geometry manifest or coordinate convention")
    if sha256(m["source"]["path"]) != m["source"]["sha256"]:
        raise ValueError("Original checksum changed; stopping")
    with Image.open(m["source"]["path"]) as source_image:
        if list(source_image.size) != [m["source"]["width"], m["source"]["height"]]:
            raise ValueError("Original dimensions disagree with manifest")
    ids = set()
    for v in m["views"]:
        if v["id"] in ids:
            raise ValueError("Duplicate view ID")
        ids.add(v["id"])
        if sha256(v["path"]) != v["sha256"]:
            raise ValueError(f"Extracted view checksum changed: {v['id']}")
        with Image.open(v["path"]) as view_image:
            if list(view_image.size) != [v["width"], v["height"]]:
                raise ValueError(f"Extracted view dimensions disagree with manifest: {v['id']}")
        if (not all(math.isfinite(float(v[k])) for k in ["hfov_degrees", "yaw_degrees", "pitch_degrees"])
                or not 0 < float(v["hfov_degrees"]) < 180 or not -90 <= float(v["pitch_degrees"]) <= 90):
            raise ValueError("Invalid view field of view")
    return m


def sphere_rays(width, height, y0, y1):
    lon = ((np.arange(width, dtype=np.float32)+.5)/width*2-1)*np.pi
    lat = (.5-(np.arange(y0, y1, dtype=np.float32)+.5)/height)*np.pi
    c = np.cos(lat)[:, None]
    return c*np.sin(lon)[None, :], np.broadcast_to(np.sin(lat)[:, None], (y1-y0, width)), c*np.cos(lon)[None, :]


def project_view(x, y, z, v):
    yaw, pitch = math.radians(v["yaw_degrees"]), math.radians(v["pitch_degrees"])
    camx, zr = x*math.cos(yaw)-z*math.sin(yaw), x*math.sin(yaw)+z*math.cos(yaw)
    camy, camz = y*math.cos(pitch)-zr*math.sin(pitch), y*math.sin(pitch)+zr*math.cos(pitch)
    t = math.tan(math.radians(v["hfov_degrees"])/2)
    # Clipping the denominator avoids divide warnings behind a view; validity excludes those rays.
    nx = camx / np.maximum(camz, 1e-8) / t
    ny = camy / np.maximum(camz, 1e-8) / (t*v["height"]/v["width"])
    edge = np.maximum(np.abs(nx), np.abs(ny))
    valid = (camz > 0) & (edge < 1)
    # The last 20% of each half-frame is feathered; overlapping views fill that area.
    s = np.clip((1-edge)/.20, 0, 1)
    weight = (s*s*(3-2*s)*valid).astype(np.float32)
    return (nx+1)*v["width"]/2-.5, (1-ny)*v["height"]/2-.5, weight


def to_linear(a):
    a = a / 255.0
    return np.where(a <= .04045, a/12.92, ((a+.055)/1.055)**2.4)


def from_linear(a):
    a = np.clip(a, 0, 1)
    return np.where(a <= .0031308, a*12.92, 1.055*a**(1/2.4)-.055)*255


def resize_rgb(a, width):
    if a.shape[1] == width:
        return a
    return np.asarray(Image.fromarray(a).resize((width, width//2), Image.Resampling.LANCZOS))


def reproject(manifest_path, output_path, edits=None, width=None, roundtrip=False, blend_mode="replacement"):
    if blend_mode not in ["replacement", "residual"]:
        raise ValueError("Blend mode must be replacement or residual")
    m = read_manifest(manifest_path)
    source_path, output_path = Path(m["source"]["path"]), Path(output_path).resolve()
    if output_path == source_path.resolve():
        raise ValueError("Output cannot overwrite the original")
    if output_path.exists():
        raise ValueError("Choose a new output path")
    native = rgb(source_path)
    sphere_size(native)
    width = width or native.shape[1]
    if width < 128 or width > 22016 or width % 2:
        raise ValueError("Output width must be even, between 128 and 22016")
    base = resize_rgb(native, width)
    del native
    height = width//2
    views_by_id = {v["id"]: v for v in m["views"]}
    edits = {"views": []} if edits is None else edits
    selected = [{"view_id": v["id"], "image_path": v["path"]} for v in m["views"]] if roundtrip else edits.get("views", [])
    loaded, seen = [], set()
    for e in selected:
        view_id = e["view_id"]
        if view_id in seen or view_id not in views_by_id:
            raise ValueError(f"Duplicate or unknown selected view: {view_id}")
        seen.add(view_id)
        v = views_by_id[view_id]
        p = Path(e["image_path"]).resolve()
        a = rgb(p)
        actual = [a.shape[1], a.shape[0]]
        if actual != [v["width"], v["height"]]:
            raise ValueError(f"Output dimensions changed for {view_id}: {actual}; explicit geometry review required, no stretching")
        mask = None
        if e.get("mask_path"):
            with Image.open(e["mask_path"]) as mi:
                if list(mi.size) != actual:
                    raise ValueError(f"Mask dimensions do not match {view_id}")
                mask = mipmaps(np.asarray(mi.convert("L")))
        levels = mipmaps(a)
        original_levels = None
        if blend_mode == "residual":
            original_levels = levels if p == Path(v["path"]).resolve() else mipmaps(rgb(v["path"]))
        loaded.append((v, levels, mask, {"view_id": view_id, "path": str(p), "sha256": sha256(p),
                                     "submitted_dimensions": [v["width"], v["height"]],
                                     "actual_output_dimensions": actual,
                                     "mask_path": e.get("mask_path"),
                                     "mask_sha256": sha256(e["mask_path"]) if e.get("mask_path") else None}, original_levels))
    out = np.empty_like(base)
    alpha_image = np.zeros((height, width), dtype=np.uint8)
    uncovered, unchanged_count, changed_outside = 0, 0, 0
    residual_clipped_pixels = 0
    overlaps = {}
    for y0 in range(0, height, STRIP):
        y1 = min(height, y0+STRIP)
        shape = (y1-y0, width)
        x, y, z = sphere_rays(width, height, y0, y1)
        total = np.zeros(shape, np.float32)
        weighted = np.zeros((*shape, 3), np.float32)
        weighted_original = np.zeros((*shape, 3), np.float32) if blend_mode == "residual" else None
        samples = []
        for v, a, mask, info, original_levels in loaded:
            u, vv, weight = project_view(x, y, z, v)
            if mask is not None:
                weight *= sample_antialiased(mask, u, vv, valid=weight > 0)/255
            color = sample_antialiased(a, u, vv, valid=weight > 0)
            total += weight
            weighted += to_linear(color) * weight[..., None]
            if original_levels is not None:
                original_color = color if original_levels is a else sample_antialiased(original_levels, u, vv, valid=weight > 0)
                weighted_original += to_linear(original_color)*weight[..., None]
            samples.append((v["id"], color, weight))
        for aa, bb in itertools.combinations(samples, 2):
            common = (aa[2] > .2) & (bb[2] > .2)
            count = int(common.sum())
            if count:
                difference = np.abs(aa[1][common]-bb[1][common]).mean(axis=1)
                key = f"{aa[0]}|{bb[0]}"
                o = overlaps.setdefault(key, {"pixels": 0, "sum_rgb_mae": 0., "pixels_mae_over_30": 0})
                o["pixels"] += count
                o["sum_rgb_mae"] += float(difference.sum())
                o["pixels_mae_over_30"] += int((difference > 30).sum())
        covered = total > 1e-8
        uncovered += int((~covered).sum())
        # Quantize the actual applied alpha, so black pixels in the exported alpha
        # image are a byte-exact preservation contract, not just an approximate preview.
        alpha = covered.astype(np.float32) if roundtrip else np.rint(np.minimum(total, 1)*255)/255
        untouched = alpha == 0
        colors = weighted / np.maximum(total, 1e-8)[..., None]
        if blend_mode == "residual":
            original_colors = weighted_original/np.maximum(total, 1e-8)[..., None]
            # R() uses identical rays, mip filters, masks and weights for both inputs.
            # Only their difference is transferred to the original source in encoded sRGB.
            difference = from_linear(colors)-from_linear(original_colors)
            raw = base[y0:y1].astype(np.float32)+alpha[..., None]*difference
            residual_clipped_pixels += int(np.any((raw < 0) | (raw > 255), axis=-1).sum())
            result = np.rint(np.clip(raw, 0, 255)).astype(np.uint8)
        else:
            blended = colors*alpha[..., None] + to_linear(base[y0:y1])*((1-alpha)[..., None])
            result = np.rint(np.clip(from_linear(blended), 0, 255)).astype(np.uint8)
        result[untouched] = base[y0:y1][untouched]
        out[y0:y1] = result
        alpha_image[y0:y1] = np.rint(alpha*255).astype(np.uint8)
        unchanged_count += int(untouched.sum())
        changed_outside += int(np.any(result[untouched] != base[y0:y1][untouched], axis=-1).sum())
    if roundtrip and uncovered:
        raise ValueError(f"Coverage failed: {uncovered} pixels have no contributing view")
    if sha256(source_path) != m["source"]["sha256"]:
        raise ValueError("Original changed during reprojection")
    save_new(Image.fromarray(out), output_path)
    coverage_path = output_path.with_name(output_path.stem+"-edit-alpha.png")
    save_new(Image.fromarray(alpha_image), coverage_path)
    overlap_reports = []
    for pair, o in overlaps.items():
        overlap_reports.append({"pair": pair, "pixels": o["pixels"],
                                "mean_rgb_mae_0_255": o["sum_rgb_mae"]/o["pixels"],
                                "fraction_mae_over_30": o["pixels_mae_over_30"]/o["pixels"]})
    operation = "resampling_roundtrip" if roundtrip else "apply_selected_views"
    if roundtrip and blend_mode == "residual":
        operation = "residual_identity_check"
    report = {"operation": operation,
              "pipeline_version": PIPELINE_VERSION,
              "composition_mode": blend_mode,
              "source_sha256": m["source"]["sha256"], "source_unchanged": True,
              "source_dimensions": [m["source"]["width"], m["source"]["height"]],
              "output_path": str(output_path), "output_sha256": sha256(output_path),
              "output_dimensions": [width, height], "convention": CONVENTION,
              "selected_views": [l[3] for l in loaded], "linear_light_blending": True,
              "coverage_alpha_path": str(coverage_path), "uncovered_pixels": uncovered,
              "applied_alpha_precision": "8-bit; exactly the exported alpha PNG; zero alpha preserves source bytes",
              "outside_coverage_pixels": unchanged_count, "changed_pixels_outside_coverage": changed_outside,
              "overlap_color_consistency": overlap_reports,
              "flags": ["High overlap disagreement: "+o["pair"] for o in overlap_reports if o["mean_rgb_mae_0_255"] > 20],
              "limitations": ["Reprojection cannot correct invented architecture or inconsistent object placement.",
                              "Color overlap and edge scores are diagnostic flags, not proof of geometric consistency.",
                              "Native-size canvas does not restore native detail after lower-resolution AI editing."]}
    if blend_mode == "residual":
        report["residual"] = {
            "formula": "clip(original + alpha * (R(edited views) - R(original views)), 0, 255), encoded sRGB; identical sampling/masks/weights for R",
            "clipped_pixel_count": residual_clipped_pixels,
            "source_pixel_difference": pixel_metrics(base, out),
            "risk": "Source-detail transfer can retain ghosts, edges or textures of removed/replaced furniture. Use replacement mode for removals/staging unless actual visual QA supports residual mode.",
            "identity_caveat": "A no-op identity result proves subtraction/compositing behavior, not lossless standalone panorama reconstruction.",
        }
    if roundtrip and blend_mode == "replacement":
        report["resampling_error"] = pixel_metrics(base, out)
    write_json(output_path.with_suffix(".json"), report)
    return report


def pixel_metrics(a, b):
    # Bound temporary memory for full-resolution panoramas.
    hist = np.zeros(256, np.int64)
    sum_abs = sum_sq = 0.
    n = 0
    maximum = 0
    for y0 in range(0, a.shape[0], STRIP):
        d = np.abs(a[y0:y0+STRIP].astype(np.int16)-b[y0:y0+STRIP].astype(np.int16))
        hist += np.bincount(d.reshape(-1), minlength=256)
        sum_abs += float(d.sum(dtype=np.float64))
        sum_sq += float(np.square(d.astype(np.float32)).sum(dtype=np.float64))
        maximum = max(maximum, int(d.max()))
        n += d.size
    mse = sum_sq/n
    return {"mae_0_255": sum_abs/n, "p95_absolute_channel_error": int(np.searchsorted(np.cumsum(hist), n*.95)),
            "max_absolute_channel_error": maximum,
            "psnr_db": 10*math.log10(255**2/mse) if mse else None,
            "pixel_identical": mse == 0}


def edge_map(a):
    gray = a.astype(np.float32) @ np.array([.2126, .7152, .0722], np.float32)
    dx = (np.roll(gray, -1, axis=1)-np.roll(gray, 1, axis=1))/2
    dy = np.empty_like(gray)
    dy[1:-1] = (gray[2:]-gray[:-2])/2
    dy[0], dy[-1] = gray[1]-gray[0], gray[-1]-gray[-2]
    return np.sqrt(dx*dx+dy*dy)


def dilate(mask, radius):
    out = np.zeros_like(mask)
    for dy in range(-radius, radius+1):
        for dx in range(-radius, radius+1):
            shifted = np.roll(mask, (dy, dx), axis=(0, 1))
            if dy > 0:
                shifted[:dy] = False
            elif dy < 0:
                shifted[dy:] = False
            out |= shifted
    return out


def seam_metric(a):
    a = a.astype(np.float32)
    jump = float(np.abs(a[:, 0]-a[:, -1]).mean())
    nearby = float(np.concatenate([np.abs(np.diff(a[:, :16], axis=1)).reshape(-1),
                                   np.abs(np.diff(a[:, -16:], axis=1)).reshape(-1)]).mean())
    return {"edge_jump_rgb_mae": jump, "near_seam_neighbor_gradient": nearby,
            "jump_to_local_gradient_ratio": jump/nearby if nearby > .01 else None}


def qa(source, candidate, destination, width=2048, protected_mask=None, landmarks=None):
    source, candidate, destination = Path(source).resolve(), Path(candidate).resolve(), Path(destination).resolve()
    original_hash = sha256(source)
    a, b = rgb(source), rgb(candidate)
    source_size, candidate_size = sphere_size(a), sphere_size(b)
    width = min(width, source_size[0], candidate_size[0])
    width -= width % 2
    a, b = resize_rgb(a, width), resize_rgb(b, width)
    mask = np.ones(a.shape[:2], bool)
    # The top/bottom 10% are excluded only from edge diagnostics, not seam/difference metrics.
    mask[:a.shape[0]//10] = False
    mask[-a.shape[0]//10:] = False
    if protected_mask:
        with Image.open(protected_mask) as im:
            if im.size != source_size:
                raise ValueError("Protected-area mask must match native source panorama dimensions")
            mask &= np.asarray(im.convert("L").resize((width, width//2), Image.Resampling.NEAREST)) >= 128
    ea, eb = edge_map(a), edge_map(b)
    # One common threshold makes before/after scores comparable but illumination can still confound them.
    threshold = max(8., float(np.percentile(ea[mask], 85))) if mask.any() else 8.
    sa, sb = (ea >= threshold) & mask, (eb >= threshold) & mask
    matched = sa & dilate(sb, 2)
    miss = float(1-matched.sum()/sa.sum()) if sa.any() else None
    seam_a, seam_b = seam_metric(a), seam_metric(b)
    landmark_results = []
    if landmarks:
        data = json.loads(Path(landmarks).read_text(encoding="utf-8"))
        if data.get("coordinate_space") != "normalized_equirectangular":
            raise ValueError("Landmarks must use normalized_equirectangular coordinates")
        for point in data.get("landmarks", []):
            first, second = point["source_uv"], point["candidate_uv"]
            if len(first) != 2 or len(second) != 2 or any(not 0 <= float(q) <= 1 for q in [*first, *second]):
                raise ValueError("Landmark coordinates must be in [0,1]")
            du = abs(first[0]-second[0])
            du = min(du, 1-du)
            displacement = math.hypot(du*width, (first[1]-second[1])*width/2)
            tolerance = float(point.get("tolerance_preview_pixels", 4))
            landmark_results.append({"name": point["name"], "displacement_preview_pixels": displacement,
                                     "tolerance_preview_pixels": tolerance, "flag": displacement > tolerance})
    flags = []
    if miss is not None and miss > .35:
        flags.append("Many source edges lack a nearby candidate edge; inspect architecture and expected content changes")
    if seam_b["edge_jump_rgb_mae"] > max(12, seam_a["edge_jump_rgb_mae"]*2):
        flags.append("Candidate seam jump exceeds the source baseline; inspect wrapped seam")
    flags.extend("Landmark displacement: "+p["name"] for p in landmark_results if p["flag"])
    report = {"source_path": str(source), "candidate_path": str(candidate), "source_sha256": original_hash,
              "candidate_sha256": sha256(candidate), "source_dimensions": list(source_size),
              "candidate_dimensions": list(candidate_size), "diagnostic_dimensions": [width, width//2],
              "pixel_difference_not_quality_score": pixel_metrics(a, b),
              "source_seam": seam_a, "candidate_seam": seam_b,
              "edge_alignment": {"threshold_0_255": threshold, "search_radius_preview_pixels": 2,
                                 "source_edge_pixels": int(sa.sum()), "fraction_source_edges_unmatched": miss,
                                 "protected_mask_supplied": bool(protected_mask),
                                 "interpretation": "A review flag only; furniture, texture and lighting edits can change edges."},
              "manual_landmarks": landmark_results, "flags": flags,
              "limitations": "No automated metric establishes physical 3D correctness or consistency between different camera positions."}
    seam_width = min(128, width//4)
    seam = np.concatenate([np.concatenate([a[:, -seam_width:], a[:, :seam_width]], axis=1),
                           np.concatenate([b[:, -seam_width:], b[:, :seam_width]], axis=1)], axis=1)
    save_new(Image.fromarray(seam), destination / "source-candidate-wrapped-seams.png")
    overlay = b.copy()
    overlay[sa & ~matched] = [255, 60, 60]
    overlay[matched] = [40, 210, 100]
    save_new(Image.fromarray(overlay), destination / "edge-review-red-unmatched-green-matched.png")
    sheet = Image.new("RGB", (4*512, 2*(384+24)), (20, 20, 25))
    draw = ImageDraw.Draw(sheet)
    for row, (label, data) in enumerate([("Source", a), ("Candidate", b)]):
        for col, yaw in enumerate([0, 90, 180, 270]):
            y = row*(384+24)
            draw.text((col*512+8, y+5), f"{label} raw yaw {yaw}", fill="white")
            sheet.paste(Image.fromarray(perspective(data, yaw, 0, 90, (512, 384))), (col*512, y+24))
    save_new(sheet, destination / "source-candidate-viewports.png")
    poles = Image.new("RGB", (1024, 2*(512+24)), (20, 20, 25))
    pole_draw = ImageDraw.Draw(poles)
    for row, (label, data) in enumerate([("Source", a), ("Candidate", b)]):
        for col, pitch in enumerate([90, -90]):
            y = row*(512+24)
            pole_draw.text((col*512+8, y+5), f"{label} {'zenith' if pitch > 0 else 'nadir'}", fill="white")
            poles.paste(Image.fromarray(perspective(data, 0, pitch, 100, (512, 512))), (col*512, y+24))
    save_new(poles, destination / "source-candidate-poles.png")
    report["source_unchanged"] = sha256(source) == original_hash
    if not report["source_unchanged"]:
        raise ValueError("Source changed during QA")
    write_json(destination / "qa.json", report)
    return report


def main():
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest="command", required=True)
    ex = sub.add_parser("extract", help="Create six overlapping, reversible perspective cuts from an immutable panorama")
    ex.add_argument("--source", required=True); ex.add_argument("--out", required=True)
    ex.add_argument("--size", type=int, default=2048); ex.add_argument("--fov", type=float, default=100)
    ex.add_argument("--yaw-offset", type=float, default=0); ex.add_argument("--provenance")
    ex.add_argument("--view-spec", help="Optional JSON array of explicit view IDs, yaw/pitch/HFOV and width/height; replaces six-view layout")
    for name in ["roundtrip", "apply"]:
        s = sub.add_parser(name)
        s.add_argument("--manifest", required=True); s.add_argument("--out", required=True)
        s.add_argument("--width", type=int)
        s.add_argument("--blend-mode", choices=["replacement", "residual"], default="replacement")
        if name == "apply":
            s.add_argument("--edits", required=True, help="JSON {views:[{view_id,image_path,mask_path?}]}")
    q = sub.add_parser("qa")
    q.add_argument("--source", required=True); q.add_argument("--candidate", required=True)
    q.add_argument("--out", required=True); q.add_argument("--width", type=int, default=2048)
    q.add_argument("--protected-mask"); q.add_argument("--landmarks")
    args = p.parse_args()
    if args.command == "extract":
        provenance = json.loads(Path(args.provenance).read_text(encoding="utf-8")) if args.provenance else None
        specs = json.loads(Path(args.view_spec).read_text(encoding="utf-8")) if args.view_spec else None
        result = extract(args.source, args.out, args.size, args.fov, args.yaw_offset, provenance, specs)
        print(json.dumps({"manifest": str(Path(args.out).resolve()/"manifest.json"), "views": len(result["views"]), "source_unchanged": True}))
    elif args.command in ["apply", "roundtrip"]:
        edits = json.loads(Path(args.edits).read_text(encoding="utf-8")) if args.command == "apply" else None
        result = reproject(args.manifest, args.out, edits, args.width, args.command == "roundtrip", args.blend_mode)
        print(json.dumps({k: result[k] for k in ["output_path", "output_dimensions", "uncovered_pixels", "changed_pixels_outside_coverage", "flags"]}))
    else:
        result = qa(args.source, args.candidate, args.out, args.width, args.protected_mask, args.landmarks)
        print(json.dumps({"report": str(Path(args.out).resolve()/"qa.json"), "flags": result["flags"]}))


if __name__ == "__main__":
    main()
