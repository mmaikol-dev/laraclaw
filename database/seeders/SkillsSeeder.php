<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedPdfSkill();
        $this->seedDockerCloudflareHostSkill();
        $this->seedInstagramOperationsSkill();
        $this->seedFacebookOperationsSkill();
        $this->seedYoutubeOperationsSkill();
        $this->seedWhatsappOperationsSkill();
    }

    private function seedPdfSkill(): void
    {
        $instructions = <<<'INSTRUCTIONS'
# PDF Processing Guide

## Overview

This guide covers essential PDF processing operations using Python libraries and command-line tools.

## Quick Start

```python
from pypdf import PdfReader, PdfWriter

# Read a PDF
reader = PdfReader("document.pdf")
print(f"Pages: {len(reader.pages)}")

# Extract text
text = ""
for page in reader.pages:
    text += page.extract_text()
```

## Python Libraries

### pypdf - Basic Operations

#### Merge PDFs
```python
from pypdf import PdfWriter, PdfReader

writer = PdfWriter()
for pdf_file in ["doc1.pdf", "doc2.pdf", "doc3.pdf"]:
    reader = PdfReader(pdf_file)
    for page in reader.pages:
        writer.add_page(page)

with open("merged.pdf", "wb") as output:
    writer.write(output)
```

#### Split PDF
```python
reader = PdfReader("input.pdf")
for i, page in enumerate(reader.pages):
    writer = PdfWriter()
    writer.add_page(page)
    with open(f"page_{i+1}.pdf", "wb") as output:
        writer.write(output)
```

#### Rotate Pages
```python
reader = PdfReader("input.pdf")
writer = PdfWriter()

page = reader.pages[0]
page.rotate(90)  # Rotate 90 degrees clockwise
writer.add_page(page)

with open("rotated.pdf", "wb") as output:
    writer.write(output)
```

### pdfplumber - Text and Table Extraction

#### Extract Text with Layout
```python
import pdfplumber

with pdfplumber.open("document.pdf") as pdf:
    for page in pdf.pages:
        text = page.extract_text()
        print(text)
```

#### Extract Tables
```python
with pdfplumber.open("document.pdf") as pdf:
    for i, page in enumerate(pdf.pages):
        tables = page.extract_tables()
        for j, table in enumerate(tables):
            print(f"Table {j+1} on page {i+1}:")
            for row in table:
                print(row)
```

### reportlab - Create PDFs

```python
from reportlab.lib.pagesizes import letter
from reportlab.pdfgen import canvas

c = canvas.Canvas("hello.pdf", pagesize=letter)
width, height = letter
c.drawString(100, height - 100, "Hello World!")
c.save()
```

**IMPORTANT**: Never use Unicode subscript/superscript characters in ReportLab PDFs. Use XML markup tags instead:
```python
chemical = Paragraph("H<sub>2</sub>O", styles['Normal'])
squared = Paragraph("x<super>2</super>", styles['Normal'])
```

## Command-Line Tools

### pdftotext
```bash
pdftotext input.pdf output.txt
pdftotext -layout input.pdf output.txt
```

### qpdf
```bash
qpdf --empty --pages file1.pdf file2.pdf -- merged.pdf
qpdf --password=mypassword --decrypt encrypted.pdf decrypted.pdf
```

## Common Tasks

### OCR Scanned PDFs
```python
import pytesseract
from pdf2image import convert_from_path

images = convert_from_path('scanned.pdf')
text = ""
for i, image in enumerate(images):
    text += f"Page {i+1}:\n"
    text += pytesseract.image_to_string(image)
```

### Fill PDF Forms
First run `check_fillable_fields.py` to determine if the PDF has fillable fields.
- If yes: use `extract_form_field_info.py` then `fill_fillable_fields.py`
- If no: use `extract_form_structure.py`, then convert to images with `convert_pdf_to_images.py`, define field data as JSON, validate with `create_validation_image.py` and `check_bounding_boxes.py`, then fill with `fill_pdf_form_with_annotations.py`

## Quick Reference

| Task | Best Tool |
|------|-----------|
| Merge PDFs | pypdf |
| Split PDFs | pypdf |
| Extract text | pdfplumber |
| Extract tables | pdfplumber |
| Create PDFs | reportlab |
| OCR scanned PDFs | pytesseract + pdf2image |
| Fill fillable forms | fill_fillable_fields.py |
| Fill non-fillable forms | fill_pdf_form_with_annotations.py |
INSTRUCTIONS;

        $skill = Skill::updateOrCreate(
            ['name' => 'pdf'],
            [
                'description' => 'Use for any PDF task: reading, extracting text/tables, merging, splitting, rotating, creating, filling forms, OCR, watermarks, encryption.',
                'category' => 'system',
                'instructions' => $instructions,
                'is_active' => true,
                'created_by' => 'agent',
            ]
        );

        $scripts = [
            [
                'filename' => 'check_fillable_fields.py',
                'description' => 'Check if a PDF has fillable form fields',
                'content' => <<<'PYTHON'
import sys
from pypdf import PdfReader


reader = PdfReader(sys.argv[1])
if (reader.get_fields()):
    print("This PDF has fillable form fields")
else:
    print("This PDF does not have fillable form fields; you will need to visually determine where to enter data")
PYTHON,
            ],
            [
                'filename' => 'check_bounding_boxes.py',
                'description' => 'Validate that form field bounding boxes do not overlap',
                'content' => <<<'PYTHON'
from dataclasses import dataclass
import json
import sys


@dataclass
class RectAndField:
    rect: list[float]
    rect_type: str
    field: dict


def get_bounding_box_messages(fields_json_stream) -> list[str]:
    messages = []
    fields = json.load(fields_json_stream)
    messages.append(f"Read {len(fields['form_fields'])} fields")

    def rects_intersect(r1, r2):
        disjoint_horizontal = r1[0] >= r2[2] or r1[2] <= r2[0]
        disjoint_vertical = r1[1] >= r2[3] or r1[3] <= r2[1]
        return not (disjoint_horizontal or disjoint_vertical)

    rects_and_fields = []
    for f in fields["form_fields"]:
        rects_and_fields.append(RectAndField(f["label_bounding_box"], "label", f))
        rects_and_fields.append(RectAndField(f["entry_bounding_box"], "entry", f))

    has_error = False
    for i, ri in enumerate(rects_and_fields):
        for j in range(i + 1, len(rects_and_fields)):
            rj = rects_and_fields[j]
            if ri.field["page_number"] == rj.field["page_number"] and rects_intersect(ri.rect, rj.rect):
                has_error = True
                if ri.field is rj.field:
                    messages.append(f"FAILURE: intersection between label and entry bounding boxes for `{ri.field['description']}` ({ri.rect}, {rj.rect})")
                else:
                    messages.append(f"FAILURE: intersection between {ri.rect_type} bounding box for `{ri.field['description']}` ({ri.rect}) and {rj.rect_type} bounding box for `{rj.field['description']}` ({rj.rect})")
                if len(messages) >= 20:
                    messages.append("Aborting further checks; fix bounding boxes and try again")
                    return messages
        if ri.rect_type == "entry":
            if "entry_text" in ri.field:
                font_size = ri.field["entry_text"].get("font_size", 14)
                entry_height = ri.rect[3] - ri.rect[1]
                if entry_height < font_size:
                    has_error = True
                    messages.append(f"FAILURE: entry bounding box height ({entry_height}) for `{ri.field['description']}` is too short for the text content (font size: {font_size}). Increase the box height or decrease the font size.")
                    if len(messages) >= 20:
                        messages.append("Aborting further checks; fix bounding boxes and try again")
                        return messages

    if not has_error:
        messages.append("SUCCESS: All bounding boxes are valid")
    return messages

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: check_bounding_boxes.py [fields.json]")
        sys.exit(1)
    with open(sys.argv[1]) as f:
        messages = get_bounding_box_messages(f)
    for msg in messages:
        print(msg)
PYTHON,
            ],
            [
                'filename' => 'convert_pdf_to_images.py',
                'description' => 'Convert PDF pages to PNG images (DPI 200, max 1000px)',
                'content' => <<<'PYTHON'
import os
import sys

from pdf2image import convert_from_path


def convert(pdf_path, output_dir, max_dim=1000):
    images = convert_from_path(pdf_path, dpi=200)

    for i, image in enumerate(images):
        width, height = image.size
        if width > max_dim or height > max_dim:
            scale_factor = min(max_dim / width, max_dim / height)
            new_width = int(width * scale_factor)
            new_height = int(height * scale_factor)
            image = image.resize((new_width, new_height))

        image_path = os.path.join(output_dir, f"page_{i+1}.png")
        image.save(image_path)
        print(f"Saved page {i+1} as {image_path} (size: {image.size})")

    print(f"Converted {len(images)} pages to PNG images")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: convert_pdf_to_images.py [input pdf] [output directory]")
        sys.exit(1)
    pdf_path = sys.argv[1]
    output_directory = sys.argv[2]
    convert(pdf_path, output_directory)
PYTHON,
            ],
            [
                'filename' => 'create_validation_image.py',
                'description' => 'Draw bounding boxes on a page image to visually validate field positions',
                'content' => <<<'PYTHON'
import json
import sys

from PIL import Image, ImageDraw


def create_validation_image(page_number, fields_json_path, input_path, output_path):
    with open(fields_json_path, 'r') as f:
        data = json.load(f)

        img = Image.open(input_path)
        draw = ImageDraw.Draw(img)
        num_boxes = 0

        for field in data["form_fields"]:
            if field["page_number"] == page_number:
                entry_box = field['entry_bounding_box']
                label_box = field['label_bounding_box']
                draw.rectangle(entry_box, outline='red', width=2)
                draw.rectangle(label_box, outline='blue', width=2)
                num_boxes += 2

        img.save(output_path)
        print(f"Created validation image at {output_path} with {num_boxes} bounding boxes")


if __name__ == "__main__":
    if len(sys.argv) != 5:
        print("Usage: create_validation_image.py [page number] [fields.json file] [input image path] [output image path]")
        sys.exit(1)
    page_number = int(sys.argv[1])
    fields_json_path = sys.argv[2]
    input_image_path = sys.argv[3]
    output_image_path = sys.argv[4]
    create_validation_image(page_number, fields_json_path, input_image_path, output_image_path)
PYTHON,
            ],
            [
                'filename' => 'extract_form_field_info.py',
                'description' => 'Extract field IDs, types, pages and positions from a fillable PDF',
                'content' => <<<'PYTHON'
import json
import sys

from pypdf import PdfReader


def get_full_annotation_field_id(annotation):
    components = []
    while annotation:
        field_name = annotation.get('/T')
        if field_name:
            components.append(field_name)
        annotation = annotation.get('/Parent')
    return ".".join(reversed(components)) if components else None


def make_field_dict(field, field_id):
    field_dict = {"field_id": field_id}
    ft = field.get('/FT')
    if ft == "/Tx":
        field_dict["type"] = "text"
    elif ft == "/Btn":
        field_dict["type"] = "checkbox"
        states = field.get("/_States_", [])
        if len(states) == 2:
            if "/Off" in states:
                field_dict["checked_value"] = states[0] if states[0] != "/Off" else states[1]
                field_dict["unchecked_value"] = "/Off"
            else:
                print(f"Unexpected state values for checkbox `${field_id}`. Its checked and unchecked values may not be correct; if you're trying to check it, visually verify the results.")
                field_dict["checked_value"] = states[0]
                field_dict["unchecked_value"] = states[1]
    elif ft == "/Ch":
        field_dict["type"] = "choice"
        states = field.get("/_States_", [])
        field_dict["choice_options"] = [{
            "value": state[0],
            "text": state[1],
        } for state in states]
    else:
        field_dict["type"] = f"unknown ({ft})"
    return field_dict


def get_field_info(reader: PdfReader):
    fields = reader.get_fields()

    field_info_by_id = {}
    possible_radio_names = set()

    for field_id, field in fields.items():
        if field.get("/Kids"):
            if field.get("/FT") == "/Btn":
                possible_radio_names.add(field_id)
            continue
        field_info_by_id[field_id] = make_field_dict(field, field_id)

    radio_fields_by_id = {}

    for page_index, page in enumerate(reader.pages):
        annotations = page.get('/Annots', [])
        for ann in annotations:
            field_id = get_full_annotation_field_id(ann)
            if field_id in field_info_by_id:
                field_info_by_id[field_id]["page"] = page_index + 1
                field_info_by_id[field_id]["rect"] = ann.get('/Rect')
            elif field_id in possible_radio_names:
                try:
                    on_values = [v for v in ann["/AP"]["/N"] if v != "/Off"]
                except KeyError:
                    continue
                if len(on_values) == 1:
                    rect = ann.get("/Rect")
                    if field_id not in radio_fields_by_id:
                        radio_fields_by_id[field_id] = {
                            "field_id": field_id,
                            "type": "radio_group",
                            "page": page_index + 1,
                            "radio_options": [],
                        }
                    radio_fields_by_id[field_id]["radio_options"].append({
                        "value": on_values[0],
                        "rect": rect,
                    })

    fields_with_location = []
    for field_info in field_info_by_id.values():
        if "page" in field_info:
            fields_with_location.append(field_info)
        else:
            print(f"Unable to determine location for field id: {field_info.get('field_id')}, ignoring")

    def sort_key(f):
        if "radio_options" in f:
            rect = f["radio_options"][0]["rect"] or [0, 0, 0, 0]
        else:
            rect = f.get("rect") or [0, 0, 0, 0]
        adjusted_position = [-rect[1], rect[0]]
        return [f.get("page"), adjusted_position]

    sorted_fields = fields_with_location + list(radio_fields_by_id.values())
    sorted_fields.sort(key=sort_key)

    return sorted_fields


def write_field_info(pdf_path: str, json_output_path: str):
    reader = PdfReader(pdf_path)
    field_info = get_field_info(reader)
    with open(json_output_path, "w") as f:
        json.dump(field_info, f, indent=2)
    print(f"Wrote {len(field_info)} fields to {json_output_path}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: extract_form_field_info.py [input pdf] [output json]")
        sys.exit(1)
    write_field_info(sys.argv[1], sys.argv[2])
PYTHON,
            ],
            [
                'filename' => 'extract_form_structure.py',
                'description' => 'Extract text labels, lines, checkboxes, and row boundaries from a non-fillable PDF',
                'content' => <<<'PYTHON'
import json
import sys
import pdfplumber


def extract_form_structure(pdf_path):
    structure = {
        "pages": [],
        "labels": [],
        "lines": [],
        "checkboxes": [],
        "row_boundaries": []
    }

    with pdfplumber.open(pdf_path) as pdf:
        for page_num, page in enumerate(pdf.pages, 1):
            structure["pages"].append({
                "page_number": page_num,
                "width": float(page.width),
                "height": float(page.height)
            })

            words = page.extract_words()
            for word in words:
                structure["labels"].append({
                    "page": page_num,
                    "text": word["text"],
                    "x0": round(float(word["x0"]), 1),
                    "top": round(float(word["top"]), 1),
                    "x1": round(float(word["x1"]), 1),
                    "bottom": round(float(word["bottom"]), 1)
                })

            for line in page.lines:
                if abs(float(line["x1"]) - float(line["x0"])) > page.width * 0.5:
                    structure["lines"].append({
                        "page": page_num,
                        "y": round(float(line["top"]), 1),
                        "x0": round(float(line["x0"]), 1),
                        "x1": round(float(line["x1"]), 1)
                    })

            for rect in page.rects:
                width = float(rect["x1"]) - float(rect["x0"])
                height = float(rect["bottom"]) - float(rect["top"])
                if 5 <= width <= 15 and 5 <= height <= 15 and abs(width - height) < 2:
                    structure["checkboxes"].append({
                        "page": page_num,
                        "x0": round(float(rect["x0"]), 1),
                        "top": round(float(rect["top"]), 1),
                        "x1": round(float(rect["x1"]), 1),
                        "bottom": round(float(rect["bottom"]), 1),
                        "center_x": round((float(rect["x0"]) + float(rect["x1"])) / 2, 1),
                        "center_y": round((float(rect["top"]) + float(rect["bottom"])) / 2, 1)
                    })

    lines_by_page = {}
    for line in structure["lines"]:
        page = line["page"]
        if page not in lines_by_page:
            lines_by_page[page] = []
        lines_by_page[page].append(line["y"])

    for page, y_coords in lines_by_page.items():
        y_coords = sorted(set(y_coords))
        for i in range(len(y_coords) - 1):
            structure["row_boundaries"].append({
                "page": page,
                "row_top": y_coords[i],
                "row_bottom": y_coords[i + 1],
                "row_height": round(y_coords[i + 1] - y_coords[i], 1)
            })

    return structure


def main():
    if len(sys.argv) != 3:
        print("Usage: extract_form_structure.py <input.pdf> <output.json>")
        sys.exit(1)

    pdf_path = sys.argv[1]
    output_path = sys.argv[2]

    print(f"Extracting structure from {pdf_path}...")
    structure = extract_form_structure(pdf_path)

    with open(output_path, "w") as f:
        json.dump(structure, f, indent=2)

    print(f"Found:")
    print(f"  - {len(structure['pages'])} pages")
    print(f"  - {len(structure['labels'])} text labels")
    print(f"  - {len(structure['lines'])} horizontal lines")
    print(f"  - {len(structure['checkboxes'])} checkboxes")
    print(f"  - {len(structure['row_boundaries'])} row boundaries")
    print(f"Saved to {output_path}")


if __name__ == "__main__":
    main()
PYTHON,
            ],
            [
                'filename' => 'fill_fillable_fields.py',
                'description' => 'Fill a fillable PDF form using a JSON file of field IDs and values',
                'content' => <<<'PYTHON'
import json
import sys

from pypdf import PdfReader, PdfWriter

from extract_form_field_info import get_field_info


def fill_pdf_fields(input_pdf_path: str, fields_json_path: str, output_pdf_path: str):
    with open(fields_json_path) as f:
        fields = json.load(f)
    fields_by_page = {}
    for field in fields:
        if "value" in field:
            field_id = field["field_id"]
            page = field["page"]
            if page not in fields_by_page:
                fields_by_page[page] = {}
            fields_by_page[page][field_id] = field["value"]

    reader = PdfReader(input_pdf_path)

    has_error = False
    field_info = get_field_info(reader)
    fields_by_ids = {f["field_id"]: f for f in field_info}
    for field in fields:
        existing_field = fields_by_ids.get(field["field_id"])
        if not existing_field:
            has_error = True
            print(f"ERROR: `{field['field_id']}` is not a valid field ID")
        elif field["page"] != existing_field["page"]:
            has_error = True
            print(f"ERROR: Incorrect page number for `{field['field_id']}` (got {field['page']}, expected {existing_field['page']})")
        else:
            if "value" in field:
                err = validation_error_for_field_value(existing_field, field["value"])
                if err:
                    print(err)
                    has_error = True
    if has_error:
        sys.exit(1)

    writer = PdfWriter(clone_from=reader)
    for page, field_values in fields_by_page.items():
        writer.update_page_form_field_values(writer.pages[page - 1], field_values, auto_regenerate=False)

    writer.set_need_appearances_writer(True)

    with open(output_pdf_path, "wb") as f:
        writer.write(f)


def validation_error_for_field_value(field_info, field_value):
    field_type = field_info["type"]
    field_id = field_info["field_id"]
    if field_type == "checkbox":
        checked_val = field_info["checked_value"]
        unchecked_val = field_info["unchecked_value"]
        if field_value != checked_val and field_value != unchecked_val:
            return f'ERROR: Invalid value "{field_value}" for checkbox field "{field_id}". The checked value is "{checked_val}" and the unchecked value is "{unchecked_val}"'
    elif field_type == "radio_group":
        option_values = [opt["value"] for opt in field_info["radio_options"]]
        if field_value not in option_values:
            return f'ERROR: Invalid value "{field_value}" for radio group field "{field_id}". Valid values are: {option_values}'
    elif field_type == "choice":
        choice_values = [opt["value"] for opt in field_info["choice_options"]]
        if field_value not in choice_values:
            return f'ERROR: Invalid value "{field_value}" for choice field "{field_id}". Valid values are: {choice_values}'
    return None


def monkeypatch_pydpf_method():
    from pypdf.generic import DictionaryObject
    from pypdf.constants import FieldDictionaryAttributes

    original_get_inherited = DictionaryObject.get_inherited

    def patched_get_inherited(self, key: str, default=None):
        result = original_get_inherited(self, key, default)
        if key == FieldDictionaryAttributes.Opt:
            if isinstance(result, list) and all(isinstance(v, list) and len(v) == 2 for v in result):
                result = [r[0] for r in result]
        return result

    DictionaryObject.get_inherited = patched_get_inherited


if __name__ == "__main__":
    if len(sys.argv) != 4:
        print("Usage: fill_fillable_fields.py [input pdf] [field_values.json] [output pdf]")
        sys.exit(1)
    monkeypatch_pydpf_method()
    input_pdf = sys.argv[1]
    fields_json = sys.argv[2]
    output_pdf = sys.argv[3]
    fill_pdf_fields(input_pdf, fields_json, output_pdf)
PYTHON,
            ],
            [
                'filename' => 'fill_pdf_form_with_annotations.py',
                'description' => 'Fill a non-fillable PDF form by adding FreeText annotations at specified bounding box positions',
                'content' => <<<'PYTHON'
import json
import sys

from pypdf import PdfReader, PdfWriter
from pypdf.annotations import FreeText


def transform_from_image_coords(bbox, image_width, image_height, pdf_width, pdf_height):
    x_scale = pdf_width / image_width
    y_scale = pdf_height / image_height

    left = bbox[0] * x_scale
    right = bbox[2] * x_scale

    top = pdf_height - (bbox[1] * y_scale)
    bottom = pdf_height - (bbox[3] * y_scale)

    return left, bottom, right, top


def transform_from_pdf_coords(bbox, pdf_height):
    left = bbox[0]
    right = bbox[2]

    pypdf_top = pdf_height - bbox[1]
    pypdf_bottom = pdf_height - bbox[3]

    return left, pypdf_bottom, right, pypdf_top


def fill_pdf_form(input_pdf_path, fields_json_path, output_pdf_path):

    with open(fields_json_path, "r") as f:
        fields_data = json.load(f)

    reader = PdfReader(input_pdf_path)
    writer = PdfWriter()

    writer.append(reader)

    pdf_dimensions = {}
    for i, page in enumerate(reader.pages):
        mediabox = page.mediabox
        pdf_dimensions[i + 1] = [mediabox.width, mediabox.height]

    annotations = []
    for field in fields_data["form_fields"]:
        page_num = field["page_number"]

        page_info = next(p for p in fields_data["pages"] if p["page_number"] == page_num)
        pdf_width, pdf_height = pdf_dimensions[page_num]

        if "pdf_width" in page_info:
            transformed_entry_box = transform_from_pdf_coords(
                field["entry_bounding_box"],
                float(pdf_height)
            )
        else:
            image_width = page_info["image_width"]
            image_height = page_info["image_height"]
            transformed_entry_box = transform_from_image_coords(
                field["entry_bounding_box"],
                image_width, image_height,
                float(pdf_width), float(pdf_height)
            )

        if "entry_text" not in field or "text" not in field["entry_text"]:
            continue
        entry_text = field["entry_text"]
        text = entry_text["text"]
        if not text:
            continue

        font_name = entry_text.get("font", "Arial")
        font_size = str(entry_text.get("font_size", 14)) + "pt"
        font_color = entry_text.get("font_color", "000000")

        annotation = FreeText(
            text=text,
            rect=transformed_entry_box,
            font=font_name,
            font_size=font_size,
            font_color=font_color,
            border_color=None,
            background_color=None,
        )
        annotations.append(annotation)
        writer.add_annotation(page_number=page_num - 1, annotation=annotation)

    with open(output_pdf_path, "wb") as output:
        writer.write(output)

    print(f"Successfully filled PDF form and saved to {output_pdf_path}")
    print(f"Added {len(annotations)} text annotations")


if __name__ == "__main__":
    if len(sys.argv) != 4:
        print("Usage: fill_pdf_form_with_annotations.py [input pdf] [fields.json] [output pdf]")
        sys.exit(1)
    input_pdf = sys.argv[1]
    fields_json = sys.argv[2]
    output_pdf = sys.argv[3]

    fill_pdf_form(input_pdf, fields_json, output_pdf)
PYTHON,
            ],
        ];

        foreach ($scripts as $scriptData) {
            $skill->scripts()->updateOrCreate(
                ['filename' => $scriptData['filename']],
                [
                    'description' => $scriptData['description'],
                    'content' => $scriptData['content'],
                ]
            );
        }
    }

    private function seedDockerCloudflareHostSkill(): void
    {
        $instructions = <<<'INSTRUCTIONS'
        # Docker + Cloudflare Tunnel Host — Operations Runbook

        You are the operator of this host. You use Docker Compose for services
        and a Cloudflare Tunnel for public exposure. There is **no inbound
        port-forwarding** — everything public flows through the tunnel.

        ## 1. Host identity

        - Host: `atlas-ThinkPad-T490` (username `atlas`).
        - OS: Ubuntu/Debian with Docker Engine + Compose plugin.
        - `cloudflared` v2026.8.2, running as systemd service `cloudflared`.

        ## 2. Docker — the WhatsApp stack

        Project directory:
        ```
        ~/docker/whatsappserver
        ```
        Compose file: `docker-compose.dev.yml`.

        Containers:

        | Container          | Purpose                     | Host port (loopback) | Notes                                    |
        |--------------------|-----------------------------|----------------------|------------------------------------------|
        | `wazuri-api`       | Backend API (NestJS)        | `127.0.0.1:2785`     | Health: `GET /api/health`                |
        | `wazuri-dashboard` | Frontend dashboard (nginx)  | `127.0.0.1:2886`     | Serves the built web UI                  |
        | `wazuri-redis`     | Redis cache (internal only) | not published        | Reached by the API over the Docker net   |

        Docker network: `openwa-network`.

        Data (persistent):
        ```
        ~/docker/whatsappserver/data   ->   /app/data   (inside wazuri-api)
        ```
        This holds the WhatsApp session + SQLite database. **Back it up; never
        delete it casually.**

        Source repos (for rebuilds):
        - API: `~/docker/whatsappserver` (and its `api/` subdir)
        - Dashboard: `~/docker/whatsappserver/dashboard`

        Typical commands (run from `~/docker/whatsappserver`):
        ```bash
        docker compose -f docker-compose.dev.yml ps
        docker compose -f docker-compose.dev.yml logs -f wazuri-api
        docker compose -f docker-compose.dev.yml restart wazuri-api
        docker compose -f docker-compose.dev.yml up -d --build
        docker compose -f docker-compose.dev.yml down
        ```

        ## 3. Cloudflare Tunnel — public access

        - Domain/zone: `sitebase.co.ke` (managed in your Cloudflare account).
        - Tunnel: `wazuri` — Tunnel ID `759073dd-6847-4d35-9325-08bf1addd5e3`.
        - The tunnel is **remotely managed** (config lives in the Cloudflare
          dashboard, not a local `config.yml`). The local systemd service only
          needs the tunnel token/credentials and runs `cloudflared tunnel run`.

        Published hostnames (route = `hostname -> local service`):

        | Hostname                       | Local target        | Status      |
        |--------------------------------|---------------------|-------------|
        | `wazuri.sitebase.co.ke`        | `localhost:2886`    | configured  |
        | `wazuri-api.sitebase.co.ke`    | `localhost:2785`    | planned     |
        | `ssh.sitebase.co.ke`           | `localhost:22`      | planned     |

        Commands:
        ```bash
        sudo systemctl status cloudflared
        sudo systemctl restart cloudflared
        sudo journalctl -u cloudflared -n 100 --no-pager
        ```

        To add/change a route: Cloudflare dashboard -> Zero Trust -> Networks ->
        Tunnels -> `wazuri` -> Public Hostname -> add `Service = http://localhost:PORT`.

        ## 4. Auth & secrets (handling rules)

        - The API expects an `X-API-Key` header.
        - API keys live at `GET/POST/DELETE /api/auth/api-keys` (admin scope).
        - The default dev key `dev-admin-key` **must be revoked** before the
          API hostname is published.
        - **Never** print, log, commit, or paste secrets (tunnel token, API
          keys, Cloudflare API tokens). Warn the owner immediately if one
          leaks.

        ## 5. Health checks (safe, read-only)

        ```bash
        curl -fsS http://localhost:2785/api/health
        curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:2886
        docker compose -f docker-compose.dev.yml ps
        docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
        sudo systemctl status cloudflared --no-pager
        ```
        Confirm the hostnames resolve publicly:
        ```bash
        curl -fsS -o /dev/null -w '%{http_code}\n' https://wazuri.sitebase.co.ke
        ```

        ## 6. Backups

        ```bash
        mkdir -p ~/docker/whatsappserver/backups
        tar czf ~/docker/whatsappserver/backups/data-$(date +%F).tgz \
          -C ~/docker/whatsappserver data
        ```
        Keep at least the last several daily archives. A backup is only good if
        you have verified you can read the archive.

        ## 7. Public exposure rule (default posture)

        - All services bind to **loopback** (`127.0.0.1`). Public access is
          **only** through the tunnel.
        - To publish a new service: add a Cloudflare Tunnel public hostname ->
          `http://localhost:PORT`. **Do not** open firewall ports or bind to
          `0.0.0.0` unless the owner explicitly asks.
        - Protect admin UIs with a Cloudflare Access policy before exposing them.

        ## 8. What YOU (the agent) are allowed to do without asking

        - Read-only checks: `docker ps`, `docker compose ps`, container logs,
          `systemctl status`, health `curl`, `df -h`, `free -m`, API GETs.
        - Restart a single container, or `cloudflared`, if it is unhealthy.
        - Create a backup of `data/` before a risky change.
        - Start a new, separate project on a free loopback port (test the port
          is free first).

        ## 9. What you MUST ask the owner before doing

        - Changing Cloudflare routes, DNS records, or Access policies.
        - Rebuilding or updating images (`--build`, `git pull` in the stack).
        - Rotating or revoking API keys.
        - Changing which ports are published, or binding to `0.0.0.0`.
        - Stopping the whole stack, or rebooting the host.
        - Installing system packages or changing the Docker daemon config.

        **Never** do the following:
        - `docker compose down -v`  (destroys volumes)
        - `docker volume prune`
        - `docker system prune -a`
        - `rm -rf ~/docker/whatsappserver/data`
        - Publish any service on a public interface or open a firewall port.
        - Expose an admin UI publicly without a Cloudflare Access policy.
        - Send messages from a WhatsApp session unless explicitly asked.
        - Print, log, commit, or paste any secret.
        - Run destructive commands you do not fully understand.

        ## 10. Open items (as of 2026-10-15)

        - [ ] VERIFY: `wazuri.sitebase.co.ke` (route target `localhost:2886`) loads.
        - [ ] VERIFY: `dev-admin-key` is revoked (a request with it should 401),
              including after an API restart.
        - [ ] VERIFY: Cloudflare Access protects the dashboard.
        - [ ] VERIFY: `wazuri-api` hostname is added only after the default key is gone.
        - [ ] VERIFY: WhatsApp session started (scan QR) if not already.
        - [ ] Next: scheduled backups of `~/docker/whatsappserver/data`.
        INSTRUCTIONS;

        Skill::updateOrCreate(
            ['name' => 'docker-cloudflare-host'],
            [
                'description' => 'Operate the host\'s Docker stack and Cloudflare Tunnel: health checks, logs, restarts, backups, publishing sites, and tunnel routes. Load before any server-room work.',
                'category' => 'system',
                'instructions' => $instructions,
                'is_active' => true,
                'created_by' => 'user',
                'version' => 1,
            ]
        );
    }

    private function seedInstagramOperationsSkill(): void
    {
        $instructions = <<<'INSTRUCTIONS'
        # Instagram Operations — Runbook

        You are the Instagram expert for this account. You publish and manage it
        through the Instagram Graph API via the `instagram` tool.

        ## Credentials

        - All credentials live in the environment file: `/tmp/laraclaw/.env`
          (keys: `INSTAGRAM_ACCESS_TOKEN`, `INSTAGRAM_ACCOUNT_ID`).
        - The `instagram` tool reads them automatically when you do not pass
          `access_token` / `account_id`.
        - **Never** print, log, commit, or paste the token. Warn the owner
          immediately if it leaks.

        ## Surfaces and formats

        - **Reels** — 9:16 vertical video; the main reach driver. `media_type=REELS`.
        - **Feed** — image or video, square (1:1) or portrait (4:5). `IMAGE` / `VIDEO`.
        - **Carousel** — several media items under one post.
        - **Stories** — 24-hour ephemeral. `media_type=STORIES`.

        ## Publishing flow (Graph API)

        1. Make the media **publicly reachable** (an `image_url` or `video_url`).
        2. `create_container` -> returns a creation id. For video/Reels, the
           container must finish processing before publishing.
        3. `publish` with the `creation_id`.
        4. Publish one item at a time; respect the API rate limits.

        ## Captions and hashtags

        - Lead with a hook in the first line; captions run up to 2,200 characters.
        - A focused set of relevant hashtags beats 30 generic ones.

        ## Action map (tool: `instagram`)

        - Read-only: `account`, `list_media`, `get_media`, `list_comments`,
          `media_insights`, `account_insights`, `list_stories`, `list_ad_accounts`.
        - Engage: `comment`, `reply`, `hide_comment` (reversible), `delete_comment`.
        - Publish: `create_container` then `publish`.
        - DMs: `list_conversations`, `get_conversation`, `get_message`,
          `send_message`, `mark_seen`.

        ## Hard rules

        - Never post, delete, comment, reply, or send a DM unless explicitly asked.
        - Never message strangers or run `business_discovery` at volume.
        - Always show the caption and hashtags before publishing.
        INSTRUCTIONS;

        Skill::updateOrCreate(
            ['name' => 'instagram-operations'],
            [
                'description' => 'Instagram expert runbook: Reels/feed/Stories formats, Graph API container->publish flow, captions, hashtags, DMs, and comment moderation. Load before publishing or moderating.',
                'category' => 'communication',
                'instructions' => $instructions,
                'is_active' => true,
                'created_by' => 'user',
                'version' => 1,
            ]
        );
    }

    private function seedFacebookOperationsSkill(): void
    {
        $instructions = <<<'INSTRUCTIONS'
        # Facebook Pages Operations — Runbook

        You are the Facebook Pages expert for this account. You publish and
        manage it through the Meta Graph API via the `facebook` tool.

        ## Credentials

        - All credentials live in the environment file: `/tmp/laraclaw/.env`
          (keys: `FACEBOOK_ACCESS_TOKEN`, `FACEBOOK_PAGE_ID`).
        - The `facebook` tool reads them automatically when you do not pass
          `access_token` / `page_id`.
        - **Never** print, log, commit, or paste them. Warn the owner
          immediately if one leaks.

        ## Token model

        - Read actions use your token directly.
        - Write actions need a **Page Access Token**, which the tool obtains for
          you by calling `GET /me/accounts`.
        - Required permissions: `pages_show_list`, `pages_manage_posts`,
          `pages_read_engagement`, `pages_manage_engagement`.

        ## Surface areas

        - Page feed posts, Facebook Reels, Stories, and comment threads.
        - Keep post text concise (the hard cap is very high, but shorter wins).

        ## Action map (tool: `facebook`)

        - Read-only: `page`, `list_posts`, `get_post`, `list_comments`, `insights`.
        - Publish/engage: `create_post`, `delete_post`, `comment`, `reply`,
          `delete_comment`, `hide_comment`, `like`, `unlike`.

        ## Insights

        - Metrics such as `page_impressions`, `page_engaged_users`, `page_fans`;
          period `day`, `week`, `days_28`, `month`, or `lifetime`.

        ## Hard rules

        - Never publish, delete, comment, reply, hide, like, or unlike unless
          explicitly asked.
        - Never tag, mention, or message people who did not engage first.
        - Respect the Graph API rate limits.
        INSTRUCTIONS;

        Skill::updateOrCreate(
            ['name' => 'facebook-operations'],
            [
                'description' => 'Facebook Pages expert runbook: feed/Reels/Stories, Page Access Token exchange, permissions, comment moderation, and Page Insights. Load before publishing or moderating.',
                'category' => 'communication',
                'instructions' => $instructions,
                'is_active' => true,
                'created_by' => 'user',
                'version' => 1,
            ]
        );
    }

    private function seedYoutubeOperationsSkill(): void
    {
        $instructions = <<<'INSTRUCTIONS'
        # YouTube Operations — Runbook

        You are the YouTube expert for this channel. You upload and manage videos
        through the YouTube Data API v3 via the `youtube` tool.

        ## Credentials

        - All credentials live in the environment file: `/tmp/laraclaw/.env`
          (keys: `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`, `YOUTUBE_REFRESH_TOKEN`).
        - The `youtube` tool mints an access token from the refresh token.
        - **Never** print, log, commit, or paste them. Warn the owner immediately
          if one leaks.

        ## Quota

        - The Data API allows **10,000 units per day**.
        - An upload costs roughly **1,600 units**, so a handful of uploads can
          exhaust the day. Plan accordingly.

        ## Uploading (resumable)

        - `upload` takes a local `video_path` plus `title`, `description`, `tags`,
          `category_id`, and `privacy_status`.
        - Titles: max 100 characters, front-load the hook.
        - Descriptions: max 5,000 characters; the first ~150 show above the fold.
        - Thumbnails: JPEG or PNG, max 2 MB.

        ## Privacy and scheduling

        - **`privacy_status` defaults to `public`** — the video goes live
          immediately. Confirm the file and metadata first.
        - `publish_at` schedules a publish and requires `privacy_status=private`.
        - Set `made_for_kids` accurately; it is a compliance field.

        ## Action map (tool: `youtube`)

        - Read-only: `channel`, `list_videos`, `get_video`, `categories`.
        - Manage: `upload`, `update_video`, `delete_video`, `set_thumbnail`.
        - OAuth: `auth_url`, `exchange_code` (one-time token setup).

        ## Hard rules

        - Because uploads are public by default, confirm the exact file path and
          the title/description/tags with the user **before** uploading.
        - Never delete a video or change an existing video's privacy, title, or
          description without explicit instruction.
        - Never set `made_for_kids` incorrectly.
        INSTRUCTIONS;

        Skill::updateOrCreate(
            ['name' => 'youtube-operations'],
            [
                'description' => 'YouTube expert runbook: uploads, resumable flow, titles/descriptions/tags/thumbnails, privacy + scheduling, the 10,000-unit daily quota, and channel analytics. Load before uploading or editing a video.',
                'category' => 'communication',
                'instructions' => $instructions,
                'is_active' => true,
                'created_by' => 'user',
                'version' => 1,
            ]
        );
    }

    private function seedWhatsappOperationsSkill(): void
    {
        $instructions = <<<'INSTRUCTIONS'
        # WhatsApp Operations — Runbook

        You contact people on WhatsApp through the self-hosted **OpenWA (Wazuri)**
        gateway via the `whatsapp` tool. The gateway runs on this host as the
        Docker container `wazuri-api` (see the `docker-cloudflare-host` runbook).

        ## Gateway

        - API base: `http://127.0.0.1:2785/api` (loopback only; public access is
          via the Cloudflare tunnel).
        - Auth: an `X-API-Key` header on every request. Operator keys can send;
          the default dev key `dev-admin-key` must not be relied on — ask the
          owner for a real operator key.
        - A message can only be sent while the session status is `ready`.
          Sending before the QR code has been scanned returns `409 Conflict`.

        ## Credentials

        - All credentials live in the environment file: `/tmp/laraclaw/.env`
          (keys: `OPENWA_API_KEY`, `OPENWA_SESSION_ID`, `OPENWA_BASE_URL`).
        - The `whatsapp` tool reads them automatically when you do not pass
          `api_key` / `session_id` / `base_url`.
        - **Never** print, log, commit, or paste the API key. Warn the owner
          immediately if it leaks.

        ## Recipients (chatId)

        - A recipient is a phone number (`628123456789`) or a WhatsApp JID.
        - Personal chat JID: `<number>@c.us`. Group JID: `<id>@g.us`.
        - A plain phone number is turned into a `@c.us` JID for you.
        - **Check before you message a new number**: `check_number` tells you
          whether a number is registered on WhatsApp. A `201` send is
          "accepted by the gateway", **not** "delivered" — a message to a number
          that is not on WhatsApp looks identical to a successful send.

        ## Action map (tool: `whatsapp`)

        - Read-only: `session`, `list_sessions`, `check_number`, `list_messages`.
        - Send: `send_text`, `send_image`, `send_document`, `send_location`.

        ## The 500 rule (important)

        OpenWA sometimes hands the message to the WhatsApp client and only then
        fails server-side, returning an internal server error (**HTTP 500**). The
        message has already left the gateway. The `whatsapp` tool therefore
        reports a 5xx on a send as **sent**, not failed. Do **not** retry a send
        that came back with a 500 — that would double-message the person.

        ## Safe-sending rules

        - **Never** message anyone unless the user explicitly asked for that
          specific message to that specific person/group.
        - Never cold-blast strangers: the first-ever message to a batch of
          numbers that never wrote to you is the fastest way to get the number
          restricted.
        - Pace yourself: a few messages per minute per session is sustainable;
          "thousands in an hour" is not.
        - Always show the exact text, recipient, and any attachment to the user
          before sending.

        ## Hard rules

        - Never print, log, or paste the API key.
        - Never send messages unless explicitly asked.
        - Verify a recipient's number with `check_number` before first contact.
        - Do not retry a send that returned a 500.
        INSTRUCTIONS;

        Skill::updateOrCreate(
            ['name' => 'whatsapp-operations'],
            [
                'description' => 'WhatsApp operations runbook: the local OpenWA/Wazuri gateway, X-API-Key auth, the ready session requirement, chatId/JID formats, the 500-means-sent rule, and safe-sending limits. Load before messaging anyone.',
                'category' => 'communication',
                'instructions' => $instructions,
                'is_active' => true,
                'created_by' => 'user',
                'version' => 1,
            ]
        );
    }
}
