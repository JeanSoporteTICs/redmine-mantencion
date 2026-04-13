import re
import unicodedata
import os
import json
import uuid
from pathlib import Path
from datetime import datetime
from fastapi import FastAPI, Request, HTTPException
import httpx

API_KEY = os.environ.get("INOUT_API_KEY", "TYxralw3BbQXFCAm")
PHONE_ID = os.environ.get("INOUT_PHONE_ID", "")

app = FastAPI()

out_dir = Path("data")
out_dir.mkdir(parents=True, exist_ok=True)
out_file = out_dir / "mensaje.json"

# Helpers

def normalize_text(text: str) -> str:
    if not isinstance(text, str):
        return ""
    text = text.lower()
    text = "".join(ch for ch in unicodedata.normalize("NFKD", text) if not unicodedata.combining(ch))
    return text


def interpret_flag(value) -> str:
    if isinstance(value, str):
        normalized = unicodedata.normalize("NFKD", value).lower().strip()
    else:
        normalized = str(value).lower().strip()
    if normalized in {"1","si","sí","true","t","y","yes"}:
        return "1"
    return "0"


def normalize_record(record: dict) -> dict:
    norm = record.copy()
    norm["estado"] = (record.get("estado", "pendiente") or "pendiente").lower()
    prioridad = record.get("prioridad", "") or "NORMAL"
    norm["prioridad"] = prioridad.upper()
    norm["hora_extra"] = interpret_flag(record.get("hora_extra", "NO"))
    problem = record.get("mensaje", "")
    unidad = record.get("unidad", "")
    if not norm.get("asunto"):
        if problem and unidad:
            norm["asunto"] = f"{problem} / {unidad}"
        else:
            norm["asunto"] = problem
    for key in ["tipo", "categoria", "unidad", "unidad_solicitante", "solicitante"]:
        if not norm.get(key):
            norm[key] = ""
    if not norm.get("tiempo_estimado"):
        norm["tiempo_estimado"] = ""
    if not norm.get("numero"):
        norm["numero"] = ""
    if not norm.get("id"):
        norm["id"] = str(uuid.uuid4())
    return norm


def load_user_phone_map() -> dict:
    path = Path("data/usuarios.json")
    if not path.exists():
        return {}
    try:
        users_raw = json.loads(path.read_text(encoding="utf-8") or "[]")
    except Exception:
        return {}
    phone_map = {}
    users_list = users_raw if isinstance(users_raw, list) else []
    for u in users_list:
        if not isinstance(u, dict):
            continue
        telefono = (u.get("numero_celular") or u.get("telefono") or u.get("anexo") or "").strip()
        if telefono:
            phone_map[normalize_phone(telefono)] = u
    return phone_map


def normalize_phone(value: str) -> str:
    digits = "".join(ch for ch in value if ch.isdigit())
    if digits.startswith("569") and len(digits) == 11:
        return "+" + digits
    if digits.startswith("9") and len(digits) == 9:
        return "+56" + digits
    if digits and not digits.startswith("+"):
        return "+" + digits
    return value.strip()


def load_list(path: Path):
    if not path.exists():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8") or "[]")
        if not isinstance(data, list):
            return []
        return [d.get("nombre", "") for d in data if isinstance(d, dict)]
    except Exception:
        return []


def infer_match(texto: str, items: list, segment_index: int) -> str:
    # Flexible match: normalize and allow substring both ways
    if not texto:
        return ""
    parts = [p.strip() for p in texto.split(",")]
    if segment_index >= len(parts):
        return ""
    target_raw = parts[segment_index]
    target = normalize_text(target_raw)
    if not target:
        return target_raw  # devolver lo que venga si no hay normalizacion
    norm_items = [(i, normalize_text(i)) for i in items]
    for original, norm in norm_items:
        if not norm:
            continue
        # match exact
        if norm == target:
            return original
        # match por palabra completa
        if re.search(rf"\\b{re.escape(norm)}\\b", target):
            return original
        # match flexible solo si la categoria tiene cierto largo (evita que 'ras' coincida con 'contraseña')
        if len(norm) >= 4 and (norm in target or target in norm):
            return original
    # sin coincidencia -> retornar vacÌo para usar el fallback definido afuera
    return ""


def load_messages():
    if not out_file.exists():
        return []
    try:
        data = json.loads(out_file.read_text(encoding="utf-8") or "[]")
        if not isinstance(data, list):
            return []
        changed = False
        for item in data:
            if isinstance(item, dict) and "id" not in item:
                item["id"] = str(uuid.uuid4())
                changed = True
        if changed:
            out_file.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
        return data
    except Exception:
        return []


def save_messages(data):
    out_file.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


@app.post("/webhook")
async def webhook(req: Request):
    raw = await req.body()
    if not raw:
        raise HTTPException(status_code=400, detail="empty body")
    try:
        body = await req.json()
    except Exception:
        text = raw.decode(errors="ignore").strip()
        body = {"message": text, "from": req.headers.get("X-From", "")}

    msg = body.get("message") or body
    if not msg:
        raise HTTPException(status_code=400, detail="no message")

    if isinstance(msg, str):
        numero = body.get("from") or body.get("phone") or ""
        texto = msg
        ts_raw = body.get("timestamp")
    else:
        numero = msg.get("from") or msg.get("phone") or body.get("from") or ""
        texto = msg.get("text") or msg.get("body") or msg.get("message") or ""
        ts_raw = msg.get("timestamp")

    if isinstance(texto, str):
        num_match = re.search(r"numero\s*=\s*([^;]+)", texto, re.IGNORECASE)
        msg_match = re.search(r"mensaje\s*=\s*(.+)", texto, re.IGNORECASE)
        if num_match:
            numero = num_match.group(1).strip()
        if msg_match:
            texto = msg_match.group(1).strip()

    try:
        ts = float(ts_raw) if ts_raw is not None else datetime.now().timestamp()
    except Exception:
        ts = datetime.now().timestamp()
    dt = datetime.fromtimestamp(ts)

    date_str = dt.strftime("%d-%m-%Y")
    categorias = load_list(Path("data/categorias.json"))
    unidades = load_list(Path("data/unidades.json"))
    categoria_inferida = infer_match(texto, categorias, 0) or "Equipos"
    unidad_inferida = infer_match(texto, unidades, 1) or "HBV"
    parts = [p.strip() for p in texto.split(",")]
    problema = parts[0] if len(parts) >= 1 else texto
    unidad_descrip = parts[1] if len(parts) >= 2 else unidad_inferida
    solicitante = parts[2] if len(parts) >= 3 else ""
    phone_map = load_user_phone_map()
    normalized_phone = normalize_phone(numero)
    asignado_info = phone_map.get(normalized_phone, {})
    asignado_nombre = ""
    asignado_id = ""
    if asignado_info:
        firstname = asignado_info.get("nombre", "").strip()
        lastname = asignado_info.get("apellido", "").strip()
        asignado_nombre = f"{firstname} {lastname}".strip() or firstname or lastname
        asignado_id = asignado_info.get("id") or ""

    raw_record = {
        "id": str(uuid.uuid4()),
        "numero": numero,
        "mensaje": texto,
        "fecha": date_str,
        "hora": dt.strftime("%H:%M:%S"),
        "fecha_inicio": date_str,
        "fecha_fin": date_str,
        "tipo": "Soporte",
        "prioridad": "NORMAL",
        "estado": "pendiente",
        "hora_extra": "NO",
        "tiempo_estimado": "",
        "categoria": categoria_inferida,
        "unidad": unidad_descrip or unidad_inferida,
        "unidad_solicitante": unidad_inferida if unidad_inferida != "NO COINCIDE" else "HBV",
        "solicitante": solicitante,
        "asunto": f"{problema} / {unidad_descrip}" if problema and unidad_descrip else problema,
        "asignado_a": asignado_id,
        "asignado_nombre": asignado_nombre,
        }
    try:
        data = load_messages()
        data.append(normalize_record(raw_record))
        save_messages(data)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"no se pudo escribir data/mensaje.json: {e}")

    if texto and numero:
        await send_text(numero, f"Eco: {texto}")

    return {"status": "ok"}


async def send_text(to: str, body: str):
    url = "https://api.inout.bot/send"
    payload = {"apikey": API_KEY, "type": "message", "to": to, "message": body}
    if PHONE_ID:
        payload["from_id"] = PHONE_ID
    headers = {"Content-Type": "application/json"}
    async with httpx.AsyncClient(timeout=10) as client:
        r = await client.post(url, json=payload, headers=headers)
        if r.status_code >= 400:
            raise HTTPException(status_code=500, detail=f"send failed: {r.text}")
